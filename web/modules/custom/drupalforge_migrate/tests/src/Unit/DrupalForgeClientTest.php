<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Exception\InvalidConfigurationException;
use Drupal\drupalforge_migrate\Exception\QuotaExceededException;
use Drupal\drupalforge_migrate\Service\DrupalForgeClient;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\BackupManifest;
use Drupal\drupalforge_migrate\Value\PreflightResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\DrupalForgeClient
 * @group drupalforge_migrate
 */
final class DrupalForgeClientTest extends TestCase {

  /**
   * @var RequestInterface[]
   */
  private array $requestLog = [];

  protected function setUp(): void {
    parent::setUp();
    // Reset the Settings singleton before each test so a prior test's
    // override (see testGetBaseUrlUsesSettingsPhpOverride) never leaks in.
    new Settings([]);
  }

  private function buildClient(array $responses): DrupalForgeClient {
    $this->requestLog = [];
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($this->requestLog));
    $httpClient = new Client(['handler' => $handlerStack]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['migration_id', 'mig-uuid-123'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('drupalforge_migrate.settings')->willReturn($config);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('drupalforge_migrate.secret', '')->willReturn('s3cr3t');

    $logger = $this->createMock(LoggerInterface::class);

    return new DrupalForgeClient($httpClient, $state, $configFactory, $logger);
  }

  private function preflightResult(): PreflightResult {
    return new PreflightResult(TRUE, [
      'drupal_version' => '10.6.18',
      'docroot' => '/var/www/html/web',
    ], []);
  }

  /**
   * @covers ::connect
   */
  public function testConnectSendsAuthHeaderAndContractShapedBody(): void {
    $client = $this->buildClient([
      new Response(200, [], json_encode(['limits' => ['max_backups' => 3], 'backups' => [], 'status' => 'ok'])),
    ]);

    $result = $client->connect($this->preflightResult());

    self::assertSame(['max_backups' => 3], $result['limits']);
    self::assertCount(1, $this->requestLog);

    $request = $this->requestLog[0]['request'];
    self::assertSame('POST', $request->getMethod());
    self::assertSame('/api/migration/v1/mig-uuid-123/connect', $request->getUri()->getPath());
    self::assertSame('Bearer mig-uuid-123.s3cr3t', $request->getHeaderLine('Authorization'));

    $body = json_decode((string) $request->getBody(), TRUE);
    self::assertArrayHasKey('origin_url', $body);
    self::assertSame('10.6.18', $body['drupal_version']);
    self::assertSame('/var/www/html/web', $body['docroot']);
    self::assertArrayHasKey('source_summary', $body);
  }

  /**
   * @covers ::listBackups
   */
  public function testListBackupsSendsGet(): void {
    $client = $this->buildClient([
      new Response(200, [], json_encode(['data' => ['backups' => [['id' => 'b1']]]])),
    ]);

    $result = $client->listBackups();

    self::assertSame([['id' => 'b1']], $result['backups']);
    $request = $this->requestLog[0]['request'];
    self::assertSame('GET', $request->getMethod());
    self::assertSame('/api/migration/v1/mig-uuid-123/backups', $request->getUri()->getPath());
  }

  /**
   * @covers ::reserveBackup
   */
  public function testReserveBackupReturnsBackupId(): void {
    $client = $this->buildClient([
      new Response(201, [], json_encode(['data' => [
        'group' => 'b1',
        'reserved_until' => 1767225600,
        'artifacts' => [['id' => 11, 'kind' => 'db'], ['id' => 12, 'kind' => 'code']],
      ]])),
    ]);

    $result = $client->reserveBackup();

    self::assertSame('b1', $result['backup_id'], 'DrupalForge names the backup set "group"; callers see it as backup_id.');
    self::assertSame(['db' => 11, 'code' => 12], $result['artifacts']);
    $request = $this->requestLog[0]['request'];
    self::assertSame('POST', $request->getMethod());
    self::assertSame('/api/migration/v1/mig-uuid-123/backups', $request->getUri()->getPath());
  }

  /**
   * @covers ::reserveBackup
   */
  public function testReserveBackupFailsLoudlyWhenNoGroupIsReturned(): void {
    $client = $this->buildClient([
      new Response(201, [], json_encode(['data' => ['artifacts' => []]])),
    ]);

    $this->expectException(DrupalForgeApiException::class);
    $this->expectExceptionMessageMatches('/no group id/');
    $client->reserveBackup();
  }

  /**
   * @covers ::reserveBackup
   */
  public function testReserveBackupThrowsQuotaExceededOn409(): void {
    $client = $this->buildClient([
      new Response(409, [], json_encode(['error' => 'quota'])),
    ]);

    $this->expectException(QuotaExceededException::class);
    $client->reserveBackup();
  }

  /**
   * @covers ::requestUpload
   */
  public function testRequestUploadReturnsUploadPlan(): void {
    $client = $this->buildClient([
      // Not reserved by this client instance (as in a Batch request or
      // --resume), so the artifact id is looked up from the backup list.
      new Response(200, [], json_encode(['data' => ['backups' => [
        ['group' => 'b1', 'artifacts' => [['id' => 11, 'kind' => 'db'], ['id' => 12, 'kind' => 'code']]],
      ]]])),
      new Response(200, [], json_encode(['data' => [
        'mode' => 'single',
        'url' => 'https://s3.example.test/put-url',
        'expires' => 1767229200,
      ]])),
    ]);

    $artifact = new ArtifactDescriptor('code', '/tmp/code.tar.gz', 1024, str_repeat('a', 64));
    $plan = $client->requestUpload('b1', $artifact);

    self::assertFalse($plan->isMultipart());
    self::assertSame('https://s3.example.test/put-url', $plan->getUrl());

    self::assertSame('GET', $this->requestLog[0]['request']->getMethod());
    $request = $this->requestLog[1]['request'];
    self::assertSame('/api/migration/v1/mig-uuid-123/backups/12/upload', $request->getUri()->getPath(), 'Uploads are authorized per artifact id, not per backup group.');
    $body = json_decode((string) $request->getBody(), TRUE);
    self::assertSame(1024, $body['size']);
    self::assertSame(str_repeat('a', 64), $body['sha256']);
    self::assertSame('code.tar.gz', $body['filename']);
  }

  /**
   * @covers ::reserveBackup
   *
   * Regression test: GuzzleHttp\Exception\ConnectException (thrown when
   * DrupalForge is unreachable - no HTTP response at all, e.g. DNS failure
   * or connection refused) does NOT extend RequestException, only
   * TransferException. A catch(RequestException) block lets it escape
   * uncaught instead of surfacing as a DrupalForgeApiException - the most
   * common real-world failure mode (an unconfigured or down DrupalForge
   * endpoint) must fail gracefully with a clear message, not a stack trace.
   */
  public function testConnectionFailureIsWrappedAsApiExceptionNotUncaught(): void {
    $mock = new MockHandler([
      new ConnectException('Failed to connect to 127.0.0.1 port 9999: Connection refused', new Request('POST', 'https://127.0.0.1:9999/api/migration/v1/mig-uuid-123/backups')),
    ]);
    $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

    // https, not http: this test is about ConnectException wrapping, not
    // the HTTPS scheme check (see testGetBaseUrlRejectsNonHttpsOverride).
    new Settings(['drupalforge_migrate_url' => 'https://127.0.0.1:9999']);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['migration_id', 'mig-uuid-123'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('drupalforge_migrate.settings')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('drupalforge_migrate.secret', '')->willReturn('s3cr3t');
    $logger = $this->createMock(LoggerInterface::class);

    $client = new DrupalForgeClient($httpClient, $state, $configFactory, $logger);

    $this->expectException(DrupalForgeApiException::class);
    $this->expectExceptionMessageMatches('/Connection refused/');
    $client->reserveBackup();
  }

  /**
   * @covers ::complete
   */
  public function testCompleteSendsArtifactsAndManifestOmittingExtraKeys(): void {
    $client = $this->buildClient([
      new Response(200, [], json_encode(['data' => ['group' => 'b1', 'ready' => TRUE]])),
    ]);

    $manifest = new BackupManifest(
      '/var/www/html/web',
      '8.4.18',
      '10.6.18',
      ['.git', 'sites/default/files'],
      [],
      ['code' => 100, 'db' => 200],
      ['code' => str_repeat('a', 64), 'db' => str_repeat('b', 64)],
      ['sites/default/settings.php'],
    );

    $client->complete('b1', [
      ['artifact' => 'code', 'sha256' => str_repeat('a', 64), 'size' => 100, 'parts' => []],
      ['artifact' => 'db', 'sha256' => str_repeat('b', 64), 'size' => 200, 'parts' => [['n' => 1, 'etag' => 'e1', 'sha256' => str_repeat('c', 64)], ['n' => 2, 'etag' => 'e2', 'sha256' => str_repeat('d', 64)]]],
    ], $manifest);

    $request = $this->requestLog[0]['request'];
    self::assertSame('POST', $request->getMethod());
    self::assertSame('/api/migration/v1/mig-uuid-123/backups/b1/complete', $request->getUri()->getPath());

    $body = json_decode((string) $request->getBody(), TRUE);
    self::assertSame(['code', 'db'], array_keys($body['artifacts']), 'DrupalForge expects artifacts keyed by kind.');
    self::assertSame(['sha256' => str_repeat('a', 64)], $body['artifacts']['code'], 'A single-PUT artifact with no parts must omit the parts key entirely, and must not leak the internal-only "size" key onto the wire.');
    self::assertSame([
      ['n' => 1, 'etag' => 'e1', 'sha256' => str_repeat('c', 64)],
      ['n' => 2, 'etag' => 'e2', 'sha256' => str_repeat('d', 64)],
    ], $body['artifacts']['db']['parts'], 'Multipart parts carry the sha256 DrupalForge verifies.');
    self::assertArrayNotHasKey('size', $body['artifacts']['db']);
    self::assertSame($manifest->toArray(), $body['metadata']);
  }

  /**
   * @covers ::getBaseUrl
   */
  public function testGetBaseUrlDefaultsToProductionWhenNotOverridden(): void {
    $client = $this->buildClient([]);
    self::assertSame('https://www.drupalforge.org', $client->getBaseUrl());
  }

  /**
   * @covers ::getBaseUrl
   */
  public function testGetBaseUrlUsesSettingsPhpOverride(): void {
    new Settings(['drupalforge_migrate_url' => 'https://stage.drupalforge.example/']);
    $client = $this->buildClient([]);
    self::assertSame('https://stage.drupalforge.example', $client->getBaseUrl(), 'The override is trimmed of a trailing slash, same as the hardcoded default.');
  }

  /**
   * @covers ::connect
   */
  public function testRequestsGoToTheConfiguredBaseUrlHost(): void {
    new Settings(['drupalforge_migrate_url' => 'https://stage.drupalforge.example']);
    $client = $this->buildClient([
      new Response(200, [], json_encode(['limits' => [], 'backups' => [], 'status' => 'ok'])),
    ]);

    $client->connect($this->preflightResult());

    self::assertSame('stage.drupalforge.example', $this->requestLog[0]['request']->getUri()->getHost());
  }

  /**
   * @covers ::getBaseUrl
   */
  public function testGetBaseUrlRejectsNonHttpsOverride(): void {
    new Settings(['drupalforge_migrate_url' => 'http://insecure.example.test']);
    $client = $this->buildClient([]);

    $this->expectException(InvalidConfigurationException::class);
    $this->expectExceptionMessageMatches('/HTTPS/');
    $client->getBaseUrl();
  }

  /**
   * @covers ::connect
   *
   * The scheme check must run before any request is sent - not just on
   * getBaseUrl(), which nothing calls automatically before an API call.
   */
  public function testConnectRejectsNonHttpsBaseUrlBeforeSendingAnyRequest(): void {
    new Settings(['drupalforge_migrate_url' => 'http://insecure.example.test']);
    // No responses queued: if a request were actually sent, MockHandler
    // would throw "no more responses" instead - a different failure than
    // the one this test asserts, proving no network call was attempted.
    $client = $this->buildClient([]);

    $this->expectException(InvalidConfigurationException::class);
    $client->connect($this->preflightResult());
  }

  /**
   * @covers ::abort
   */
  public function testAbortSendsDelete(): void {
    $client = $this->buildClient([
      new Response(204),
    ]);

    $client->abort('b1');

    $request = $this->requestLog[0]['request'];
    self::assertSame('DELETE', $request->getMethod());
    self::assertSame('/api/migration/v1/mig-uuid-123/backups/b1', $request->getUri()->getPath());
  }

  /**
   * @covers ::connect
   *
   * Regression test: request() only surfaced a non-2xx response as an
   * exception by relying on the injected Guzzle client's default
   * `http_errors => TRUE` behavior. A site can disable that globally via
   * $settings['http_client_config']['http_errors'] = FALSE in
   * settings.php (a documented, supported Drupal core override affecting
   * the shared http_client service this module also uses) - in that case
   * a 4xx/5xx response no longer throws on its own, and without its own
   * explicit status-code check, request() would silently return the error
   * body as if it were a successful response, letting BackupOrchestrator
   * mark a backup `ready` even though DrupalForge never accepted it.
   */
  public function testNonThrowingErrorResponseStillSurfacesAsApiException(): void {
    $mock = new MockHandler([
      new Response(500, [], json_encode(['error' => 'internal server error'])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($this->requestLog));
    // Mirrors a site that disabled Guzzle's http_errors globally - the
    // client no longer throws for 4xx/5xx responses on its own.
    $httpClient = new Client(['handler' => $handlerStack, 'http_errors' => FALSE]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['migration_id', 'mig-uuid-123'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('drupalforge_migrate.settings')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('drupalforge_migrate.secret', '')->willReturn('s3cr3t');
    $logger = $this->createMock(LoggerInterface::class);

    $client = new DrupalForgeClient($httpClient, $state, $configFactory, $logger);

    $this->expectException(DrupalForgeApiException::class);
    $this->expectExceptionMessageMatches('/500/');
    $client->connect($this->preflightResult());
  }

  /**
   * @covers ::reserveBackup
   *
   * Same gap as above, but for the special-cased 409 (quota exceeded)
   * status - it must still map to QuotaExceededException even when Guzzle
   * didn't throw on its own.
   */
  public function testNonThrowing409StillMapsToQuotaExceeded(): void {
    $mock = new MockHandler([
      new Response(409, [], json_encode(['error' => 'quota'])),
    ]);
    $httpClient = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => FALSE]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['migration_id', 'mig-uuid-123'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('drupalforge_migrate.settings')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('drupalforge_migrate.secret', '')->willReturn('s3cr3t');
    $logger = $this->createMock(LoggerInterface::class);

    $client = new DrupalForgeClient($httpClient, $state, $configFactory, $logger);

    $this->expectException(QuotaExceededException::class);
    $client->reserveBackup();
  }

}
