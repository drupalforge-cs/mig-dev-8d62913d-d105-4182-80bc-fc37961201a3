<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Service\PreflightService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the connection to DrupalForge.
 *
 * The DrupalForge URL itself is not a form field: it's hardcoded to
 * production in DrupalForgeClient::DEFAULT_URL, overridable only via
 * `$settings['drupalforge_migrate_url']` in settings.php (for dev/stage
 * testing against a non-production DrupalForge). `migration_id` is ordinary
 * exported config. The Migration Secret is write-only here: submitting a
 * value writes it to State and it is never redisplayed, so it never
 * round-trips back into the form and never ends up in exported config.
 */
final class DrupalForgeMigrateSettingsForm extends ConfigFormBase {

  private const SETTINGS = 'drupalforge_migrate.settings';
  private const STATE_SECRET_KEY = 'drupalforge_migrate.secret';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly PreflightService $preflightService,
    private readonly DrupalForgeClientInterface $drupalForgeClient,
  ) {
    parent::__construct($configFactory);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('drupalforge_migrate.preflight_service'),
      $container->get('drupalforge_migrate.drupalforge_client'),
    );
  }

  public function getFormId(): string {
    return 'drupalforge_migrate_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config(self::SETTINGS);

    $form['migration_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Migration ID'),
      '#default_value' => $config->get('migration_id'),
      '#required' => TRUE,
    ];

    $form['secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Migration secret'),
      '#description' => $this->t('Leave blank to keep the current secret. This value is never shown again after saving.'),
    ];

    if (!empty($form_state->get('drupalforge_migrate_connect_result'))) {
      $form['connection_result'] = $this->buildConnectionResult($form_state->get('drupalforge_migrate_connect_result'));
    }

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::submitForm', '::testConnection'],
      '#weight' => 10,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('migration_id', $form_state->getValue('migration_id'))
      ->save();

    $secret = $form_state->getValue('secret');
    if (is_string($secret) && $secret !== '') {
      $this->state->set(self::STATE_SECRET_KEY, $secret);
    }

    parent::submitForm($form, $form_state);
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    try {
      $preflight = $this->preflightService->run();
      $response = $this->drupalForgeClient->connect($preflight);
      $form_state->set('drupalforge_migrate_connect_result', ['ok' => TRUE] + $response);
      $this->messenger()->addStatus($this->t('Connected to DrupalForge successfully.'));
    }
    catch (DrupalForgeApiException $e) {
      $form_state->set('drupalforge_migrate_connect_result', ['ok' => FALSE, 'error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Could not connect to DrupalForge: @message', ['@message' => $e->getMessage()]));
    }
    $form_state->setRebuild();
  }

  /**
   * @param array{ok: bool, limits?: array, backups?: array, error?: string} $result
   */
  private function buildConnectionResult(array $result): array {
    if (empty($result['ok'])) {
      return [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Connection failed: @error', ['@error' => $result['error'] ?? 'unknown error']) . '</p>',
      ];
    }

    $limitsItems = [];
    foreach ($result['limits'] ?? [] as $key => $value) {
      $limitsItems[] = $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
    }

    $backupsItems = [];
    foreach ($result['backups'] ?? [] as $backup) {
      $backupsItems[] = is_array($backup) ? json_encode($backup) : (string) $backup;
    }

    return [
      'limits' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Limits'),
        '#items' => $limitsItems,
      ],
      'backups' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Existing backups'),
        '#items' => $backupsItems ?: [$this->t('No backups yet.')],
      ],
    ];
  }

}
