<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Exception\InvalidConfigurationException;
use Drupal\drupalforge_migrate\Exception\QuotaExceededException;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\BackupManifest;
use Drupal\drupalforge_migrate\Value\PreflightResult;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Talks to the DrupalForge migration API.
 *
 * The DrupalForge base URL is hardcoded to production (self::DEFAULT_URL)
 * rather than an admin-editable field, since there is exactly one production
 * DrupalForge and exposing it as a form field invites accidental
 * misconfiguration. A developer testing against a dev/stage DrupalForge
 * instance overrides it in settings.php:
 *   $settings['drupalforge_migrate_url'] = 'https://stage.drupalforge.org';
 * Any non-HTTPS override throws InvalidConfigurationException immediately -
 * before any request is sent, including connect() - see assertHttps().
 * `migration_id` still comes from config (drupalforge_migrate.settings); the
 * migration secret is read exclusively from State (never config), so it can
 * never be exported via `drush cex`.
 */
final class DrupalForgeClient implements DrupalForgeClientInterface {

  /**
   * Production DrupalForge base URL, used unless overridden in settings.php.
   */
  private const DEFAULT_URL = 'https://www.drupalforge.org';

  private const SETTINGS_KEY = 'drupalforge_migrate_url';
  private const STATE_SECRET_KEY = 'drupalforge_migrate.secret';
  private const CONFIG_NAME = 'drupalforge_migrate.settings';

  /**
   * Artifact ids per backup group, as DrupalForge numbers them: uploads are
   * authorized per artifact id, not per backup group.
   *
   * @var array<string, array<string, int>>
   */
  private array $artifactIds = [];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array{limits?: array, backups?: array, status?: string}
   */
  public function connect(PreflightResult $preflight): array {
    $checks = $preflight->getChecks();
    return $this->request('POST', 'connect', [
      'origin_url' => $this->originUrl(),
      'php_version' => PHP_VERSION,
      'drupal_version' => $checks['drupal_version'] ?? 'unknown',
      'docroot' => $checks['docroot'] ?? '',
      'source_summary' => $preflight->toArray(),
    ]);
  }

  /**
   * @return array{backups?: array}
   */
  public function listBackups(): array {
    return $this->request('GET', 'backups');
  }

  /**
   * Reserves a backup set (one db and one code artifact) on DrupalForge.
   *
   * DrupalForge identifies the set by a `group` UUID; it is exposed here as
   * `backup_id` so callers treat it as one opaque backup handle.
   *
   * @return array{backup_id: string, reserved_until: string, artifacts: array<string, int>}
   *
   * @throws \Drupal\drupalforge_migrate\Exception\QuotaExceededException
   *   When DrupalForge rejects the reservation (HTTP 409).
   */
  public function reserveBackup(): array {
    $data = $this->request('POST', 'backups');
    $group = (string) ($data['group'] ?? '');
    if ($group === '') {
      throw new DrupalForgeApiException('DrupalForge reserved a backup but returned no group id.');
    }
    $this->rememberArtifactIds($group, $data['artifacts'] ?? []);
    return [
      'backup_id' => $group,
      'reserved_until' => (string) ($data['reserved_until'] ?? ''),
      'artifacts' => $this->artifactIds[$group] ?? [],
    ];
  }

  public function requestUpload(string $backupId, ArtifactDescriptor $artifact): UploadPlan {
    $artifactId = $this->artifactId($backupId, $artifact->getArtifact());
    $data = $this->request('POST', sprintf('backups/%d/upload', $artifactId), $artifact->toRequestBody());
    return UploadPlan::fromResponse($data);
  }

  /**
   * @param array<int, array{artifact: string, sha256: string, parts?: array<int, array{n: int, etag: string}>}> $artifactResults
   */
  public function complete(string $backupId, array $artifactResults, BackupManifest $manifest): void {
    // DrupalForge expects the artifacts keyed by kind ("db" / "code"), each
    // with its sha256 and - for multipart uploads - the ETag and sha256 of
    // every part. Callers (BackupOrchestrator) also carry a `size` key
    // internally for local manifest-building, which must never leak onto
    // the wire.
    $artifacts = [];
    foreach ($artifactResults as $result) {
      $body = ['sha256' => $result['sha256']];
      if (!empty($result['parts'])) {
        $body['parts'] = $result['parts'];
      }
      $artifacts[$result['artifact']] = $body;
    }

    $this->request('POST', sprintf('backups/%s/complete', $backupId), [
      'artifacts' => $artifacts,
      'metadata' => $manifest->toArray(),
    ]);
  }

  public function abort(string $backupId): void {
    $this->request('DELETE', sprintf('backups/%s', $backupId));
  }

  public function getBaseUrl(): string {
    return rtrim($this->settings()['url'], '/');
  }

  /**
   * @param mixed $artifacts
   *   The `artifacts` list from a DrupalForge backup group: [{id, kind}, ...].
   */
  private function rememberArtifactIds(string $group, mixed $artifacts): void {
    foreach (\is_array($artifacts) ? $artifacts : [] as $artifact) {
      if (isset($artifact['kind'], $artifact['id'])) {
        $this->artifactIds[$group][(string) $artifact['kind']] = (int) $artifact['id'];
      }
    }
  }

  /**
   * Finds DrupalForge's numeric id for one artifact of a backup group.
   *
   * Uses the ids remembered from reserveBackup() when this same process
   * reserved the group; otherwise (Batch API operations, --resume) looks the
   * group up on DrupalForge.
   */
  private function artifactId(string $group, string $kind): int {
    if (!isset($this->artifactIds[$group][$kind])) {
      foreach ($this->listBackups()['backups'] ?? [] as $backup) {
        if (($backup['group'] ?? NULL) === $group) {
          $this->rememberArtifactIds($group, $backup['artifacts'] ?? []);
        }
      }
    }
    if (!isset($this->artifactIds[$group][$kind])) {
      throw new DrupalForgeApiException(sprintf("DrupalForge has no '%s' artifact for backup %s.", $kind, $group));
    }
    return $this->artifactIds[$group][$kind];
  }

  private function originUrl(): string {
    return $GLOBALS['base_url'] ?? '';
  }

  private function settings(): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $url = (string) Settings::get(self::SETTINGS_KEY, self::DEFAULT_URL);
    $this->assertHttps($url);
    return [
      'url' => $url,
      'migration_id' => (string) $config->get('migration_id'),
    ];
  }

  /**
   * Enforces this module's non-negotiable HTTPS-only requirement (see
   * AGENTS.md). The admin UI can no longer set a non-HTTPS URL (the field
   * was removed entirely - see the class docblock), so the only remaining
   * way to misconfigure this is a settings.php override; this check runs on
   * every settings() call - i.e. before ANY request, including connect() -
   * so a bad override fails loudly and immediately rather than silently
   * sending the migration secret in plaintext.
   */
  private function assertHttps(string $url): void {
    $scheme = parse_url($url, \PHP_URL_SCHEME);
    if ($scheme !== 'https') {
      throw new InvalidConfigurationException(sprintf(
        "DrupalForge URL must use HTTPS; got: '%s'. Check \$settings['%s'] in settings.php.",
        $url,
        self::SETTINGS_KEY,
      ));
    }
  }

  private function secret(): string {
    return (string) $this->state->get(self::STATE_SECRET_KEY, '');
  }

  private function baseUri(): string {
    $settings = $this->settings();
    return rtrim($settings['url'], '/') . '/api/migration/v1/' . $settings['migration_id'] . '/';
  }

  private function authHeader(): string {
    return 'Bearer ' . $this->settings()['migration_id'] . '.' . $this->secret();
  }

  /**
   * @return array<string, mixed>
   */
  private function request(string $method, string $path, array $body = []): array {
    $options = [
      'headers' => [
        'Authorization' => $this->authHeader(),
        'Accept' => 'application/json',
      ],
    ];
    if ($method !== 'GET' && $method !== 'DELETE') {
      $options['json'] = $body;
    }

    try {
      $response = $this->httpClient->request($method, $this->baseUri() . $path, $options);
    }
    catch (GuzzleException $e) {
      // GuzzleException is the common interface for both RequestException
      // (a response was received, e.g. 4xx/5xx) and ConnectException (no
      // response at all - DNS failure, connection refused, timeout).
      // ConnectException does NOT extend RequestException, so catching only
      // RequestException here would let connection-level failures (by far
      // the most common failure mode against an unreachable DrupalForge)
      // escape uncaught instead of surfacing as a DrupalForgeApiException.
      $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 0) : 0;
      $this->logger->error('DrupalForge API request to @path failed: @message', [
        '@path' => $path,
        '@message' => $e->getMessage(),
      ]);
      if ($statusCode === 409) {
        throw new QuotaExceededException('DrupalForge rejected the backup reservation: quota exceeded.', 409, $e);
      }
      throw new DrupalForgeApiException($e->getMessage(), $statusCode, $e);
    }

    $statusCode = $response->getStatusCode();
    if ($statusCode >= 400) {
      // Guzzle's http_errors option (default TRUE) normally throws for a
      // non-2xx response already, caught above - but a site can disable it
      // globally via $settings['http_client_config']['http_errors'] =
      // FALSE in settings.php (a documented, supported override affecting
      // the shared http_client service), in which case a non-2xx response
      // reaches here without ever throwing. Never trust "the call didn't
      // throw" as "it succeeded" - the status must always be checked
      // explicitly, or a failed request could be silently treated as
      // success (e.g. a backup marked `ready` when DrupalForge actually
      // rejected `complete`).
      $this->logger->error('DrupalForge API request to @path returned HTTP @status.', [
        '@path' => $path,
        '@status' => $statusCode,
      ]);
      if ($statusCode === 409) {
        throw new QuotaExceededException('DrupalForge rejected the backup reservation: quota exceeded.', $statusCode);
      }
      throw new DrupalForgeApiException(sprintf('DrupalForge returned HTTP %d for %s.', $statusCode, $path), $statusCode);
    }

    $contents = (string) $response->getBody();
    if ($contents === '') {
      return [];
    }
    $decoded = json_decode($contents, TRUE);
    if (!\is_array($decoded)) {
      return [];
    }
    // DrupalForge wraps every success payload as {"data": ...}.
    return \is_array($decoded['data'] ?? NULL) ? $decoded['data'] : $decoded;
  }

}
