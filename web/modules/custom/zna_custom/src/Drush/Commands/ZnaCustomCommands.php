<?php

declare(strict_types=1);

namespace Drupal\zna_custom\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\UnstructuredListData;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Update\EquivalentUpdate;
use Drupal\Core\Update\UpdateRegistry;
use Drupal\Core\Utility\Error;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\core\DocsCommands;
use Drush\Commands\DrushCommands;
use Drush\Drupal\DrupalUtil;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\Log\SuccessInterface;
use Psr\Log\LogLevel;

/**
 * A Drush commandfile. "drush zupdb" gets around a limitation on ZNA's
 * MidPhase hosting that prevents Drush commands from calling other Drush
 * commands because the drush executable is not actually executable.
 */
final class ZnaCustomCommands extends DrushCommands {
  use AutowireTrait;

  const UPDATEDB = 'zupdatedb';
  const STATUS = 'zupdatedb:status';
  const BATCH_PROCESS = 'zupdatedb:batch-process';

  public function __construct(
    private readonly SiteAliasManagerInterface $siteAliasManager
  ) {
    parent::__construct();
  }

  /**
   * Note - can't inject @database since a method below is static.
   */

  /**
   * Apply any database updates required (as with running update.php).
   */
  #[CLI\Command(name: self::UPDATEDB, aliases: ['zupdb'])]
  #[CLI\Option(name: 'cache-clear', description: 'Clear caches upon completion.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Topics(topics: [DocsCommands::DEPLOY])]
  #[CLI\Kernel(name: 'update')]
  public function zupdatedb($options = ['cache-clear' => true]): int
  {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    require_once DRUPAL_ROOT . '/core/includes/update.inc';
    drupal_load_updates();

    // Disables extensions that have a lower Drupal core major version, or too high of a PHP requirement.
    // Those are rare, and this function does a full rebuild. So commenting it out for now.
    // update_fix_compatibility();

    // Check requirements before updating.
    if (!$this->updateCheckRequirements()) {
      if (!$this->io()->confirm(dt('Requirements check reports errors. Do you wish to continue?'))) {
        throw new UserAbortException();
      }
    }

    $status_options = ['strict' => 0];
    $status_options = array_merge(Drush::redispatchOptions(), $status_options);

    if ($output = $this->zupdatedbStatus($status_options)) {
      // We have pending updates - let's run em.
      $this->output()->writeln($output);
      if (!$this->io()->confirm(dt('Do you wish to run the specified pending updates?'))) {
        throw new UserAbortException();
      }
      if ($this->getConfig()->simulate()) {
        $success = true;
      } else {
        $success = $this->updateBatch();
      }

      $level = $success ? SuccessInterface::SUCCESS : LogLevel::ERROR;
      $this->logger()->log($level, dt('Finished performing updates.'));
    } else {
      $this->logger()->success(dt('No pending updates.'));
      $success = true;
    }
    // Flush all caches regardless of whether updates ran. When Drupal
    // core performs database updates it also clears the cache at the
    // end. This ensures that we are compatible with updates that rely
    // on this behavior.
    if ($options['cache-clear']) {
      drupal_flush_all_caches();
    }

    return $success ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * List any pending database updates.
   */
  #[CLI\Command(name: self::STATUS, aliases: ['zupdbst', 'zupdatedb-status'])]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Kernel(name: 'update')]
  #[CLI\FieldLabels(labels: [
    'module' => 'Module',
    'update_id' => 'Update ID',
    'description' => 'Description',
    'type' => 'Type'
  ])]
  #[CLI\DefaultTableFields(fields: ['module', 'update_id', 'type', 'description'])]
  #[CLI\FilterDefaultField(field: 'type')]
  public function zupdatedbStatus($options = ['format' => 'table']): ?RowsOfFields
  {
    require_once DRUSH_DRUPAL_CORE . '/includes/install.inc';
    drupal_load_updates();
    [$pending, $start, $warnings] = $this->getUpdatedbStatus($options);

    // Output any warnings.
    $return = null;
    foreach ($warnings as $module => $warning) {
      $this->logger()->warning(dt('!module: !warning', ['!module' => $module, '!warning' => $warning]));
    }
    if (empty($pending)) {
      $this->logger()->success(dt("No database updates required."));
    } else {
      $return = new RowsOfFields($pending);
    }
    return $return;
  }

  /**
   * Process operations in the specified batch set.
   */
  #[CLI\Command(name: self::BATCH_PROCESS)]
  #[CLI\Help(hidden: true)]
  #[CLI\Argument(name: 'batch_id', description: 'The batch id that will be processed.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Kernel(name: 'update')]
  public function zprocess(string $batch_id, $options = ['format' => 'json']): UnstructuredListData
  {
    $result = drush_batch_command($batch_id);
    return new UnstructuredListData($result);
  }

  /**
   * Perform one update and store the results which will later be displayed on
   * the finished page.
   *
   * An update function can force the current and all later updates for this
   * module to abort by returning a $ret array with an element like:
   * $ret['#abort'] = array('success' => FALSE, 'query' => 'What went wrong');
   * The schema version will not be updated in this case, and all the
   * aborted updates will continue to appear on update.php as updates that
   * have not yet been run.
   *
   * This method is static since since it is called by _drush_batch_worker().
   *
   * @param string $module
   *   The module whose update will be run.
   * @param int $number
   *   The update number to run.
   * @param array $dependency_map
   *   The update dependency map.
   * @param array $context
   *   The batch context object.
   */
  public static function updateDoOne(string $module, int $number, array $dependency_map, array $context): void
  {
    $function = $module . '_update_' . $number;

    // Disable config entity overrides.
    if (!defined('MAINTENANCE_MODE')) {
      define('MAINTENANCE_MODE', 'update');
    }

    // If this update was aborted in a previous step, or has a dependency that
    // was aborted in a previous step, go no further.
    if (!empty($context['results']['#abort']) && array_intersect($context['results']['#abort'], array_merge($dependency_map, [$function]))) {
      return;
    }

    $context['log'] = false;

    \Drupal::moduleHandler()->loadInclude($module, 'install');

    $ret = [];
    $update_hook_registry = \Drupal::service('update.update_hook_registry');
    $equivalent_update = null;
    if (method_exists($update_hook_registry, 'getEquivalentUpdate')) {
      $equivalent_update = \Drupal::service('update.update_hook_registry')->getEquivalentUpdate($module, $number);
    }
    if ($equivalent_update && $equivalent_update instanceof EquivalentUpdate) {
      $ret['results']['query'] = $equivalent_update->toSkipMessage();
      $ret['results']['success'] = true;
      $context['sandbox']['#finished'] = true;
    } elseif (function_exists($function)) {
      try {
        if ($context['log']) {
          Database::startLog($function);
        }

        if (empty($context['results'][$module][$number]['type'])) {
          Drush::logger()->notice("Update started: $function");
        }

        $ret['results']['query'] = $function($context['sandbox']);
        $ret['results']['success'] = true;
        $ret['type'] = 'update';
      } catch (\Throwable $e) {
        // PHP 7 introduces Throwable, which covers both Error and Exception throwables.
        $ret['#abort'] = ['success' => false, 'query' => $e->getMessage()];
        Drush::logger()->error($e->getMessage());
      }

      if ($context['log']) {
        $ret['queries'] = Database::getLog($function);
      }
    } else {
      $ret['#abort'] = ['success' => false];
      Drush::logger()->warning(dt('Update function @function not found in file @filename', [
        '@function' => $function,
        '@filename' => "$module.install",
      ]));
    }

    if (isset($context['sandbox']['#finished'])) {
      $context['finished'] = $context['sandbox']['#finished'];
      unset($context['sandbox']['#finished']);
    }

    if (!isset($context['results'][$module])) {
      $context['results'][$module] = [];
    }
    if (!isset($context['results'][$module][$number])) {
      $context['results'][$module][$number] = [];
    }
    $context['results'][$module][$number] = array_merge($context['results'][$module][$number], $ret);

    // Log the message that was returned.
    if (!empty($ret['results']['query'])) {
      Drush::logger()->notice(strip_tags((string) $ret['results']['query']));
    }

    if (!empty($ret['#abort'])) {
      // Record this function in the list of updates that were aborted.
      $context['results']['#abort'][] = $function;
      Drush::logger()->error("Update failed: $function");
    }

    // Record the schema update if it was completed successfully.
    if ($context['finished'] >= 1 && empty($ret['#abort'])) {
      \Drupal::service("update.update_hook_registry")->setInstalledVersion($module, $number);
      $context['message'] = "Update completed: $function";
    }
  }

  /**
   * Batch command that executes a single post-update.
   *
   * @param string $function
   *   The post-update function to execute.
   *   The batch context object.
   */
  public static function updateDoOnePostUpdate(string $function, array $context): void
  {
    $ret = [];

    // Disable config entity overrides.
    if (!defined('MAINTENANCE_MODE')) {
      define('MAINTENANCE_MODE', 'update');
    }

    // If this update was aborted in a previous step, or has a dependency that was
    // aborted in a previous step, go no further.
    if (!empty($context['results']['#abort'])) {
      return;
    }

    [$extension, $name] = explode('_post_update_', $function, 2);
    \Drupal::service('update.post_update_registry')->getUpdateFunctions($extension);

    if (function_exists($function)) {
      if (empty($context['results'][$extension][$name]['type'])) {
        Drush::logger()->notice("Update started: $function");
      }
      try {
        $ret['results']['query'] = $function($context['sandbox']);
        $ret['results']['success'] = true;
        $ret['type'] = 'post_update';

        if (!isset($context['sandbox']['#finished']) || (isset($context['sandbox']['#finished']) && $context['sandbox']['#finished'] >= 1)) {
          \Drupal::service('update.post_update_registry')->registerInvokedUpdates([$function]);
        }
      } catch (\Exception $e) {
        // @TODO We may want to do different error handling for different exception
        // types, but for now we'll just log the exception and return the message
        // for printing.
        // @see https://www.drupal.org/node/2564311
        Drush::logger()->error($e->getMessage());

        $variables = Error::decodeException($e);
        unset($variables['backtrace']);
        $ret['#abort'] = [
          'success' => false,
          'query' => t('%type: @message in %function (line %line of %file).', $variables),
        ];
      }
    } else {
      $ret['#abort'] = ['success' => false];
      Drush::logger()->warning(dt('Post update function @function not found.', [
        '@function' => $function
      ]));
    }

    if (isset($context['sandbox']['#finished'])) {
      $context['finished'] = $context['sandbox']['#finished'];
      unset($context['sandbox']['#finished']);
    }
    if (!isset($context['results'][$extension][$name])) {
      $context['results'][$extension][$name] = [];
    }
    $context['results'][$extension][$name] = array_merge($context['results'][$extension][$name], $ret);

    // Log the message that was returned.
    if (!empty($ret['results']['query'])) {
      Drush::logger()->notice(strip_tags((string) $ret['results']['query']));
    }

    if (!empty($ret['#abort'])) {
      // Record this function in the list of updates that were aborted.
      $context['results']['#abort'][] = $function;
      Drush::logger()->error("Update failed: $function");
    } elseif ($context['finished'] == 1 && empty($ret['#abort'])) {
      $context['message'] = "Update completed: $function";
    }
  }

  /**
   * Batch finished callback.
   *
   * @param boolean $success Whether the batch ended without a fatal error.
   */
  public function updateFinished(bool $success, array $results, array $operations): void
  {
    // No longer used. Flush moved to \Drush\Commands\core\UpdateDBCommands::updatedb.
  }

  /**
   * Start the database update batch process.
   */
  public function updateBatch(): bool
  {
    $start = $this->getUpdateList();
    // Resolve any update dependencies to determine the actual updates that will
    // be run and the order they will be run in.
    $updates = update_resolve_dependencies($start);

    // Store the dependencies for each update function in an array which the
    // batch API can pass in to the batch operation each time it is called. (We
    // do not store the entire update dependency array here because it is
    // potentially very large.)
    $dependency_map = [];
    foreach ($updates as $function => $update) {
      $dependency_map[$function] = empty($update['reverse_paths']) ? [] : array_keys($update['reverse_paths']);
    }

    $operations = [];

    foreach ($updates as $update) {
      if ($update['allowed']) {
        // Set the installed version of each module so updates will start at the
        // correct place. (The updates are already sorted, so we can simply base
        // this on the first one we come across in the above foreach loop.)
        if (isset($start[$update['module']])) {
          // TODO: setInstalledVersion in update.update_hook_registry introduced in Drupal 9.3.0
          if (!function_exists('drupal_set_installed_schema_version')) {
            \Drupal::service("update.update_hook_registry")->setInstalledVersion($update['module'], $update['number'] - 1);
          } else {
            drupal_set_installed_schema_version($update['module'], $update['number'] - 1);
          }
          unset($start[$update['module']]);
        }
        // Add this update function to the batch.
        $function = $update['module'] . '_update_' . $update['number'];
        $operations[] = ['\Drush\Commands\core\UpdateDBCommands::updateDoOne', [$update['module'], $update['number'], $dependency_map[$function]]];
      }
    }

    // Lastly, apply post update hooks.
    $post_updates = \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions();
    if ($post_updates) {
      if ($operations) {
        // Only needed if we performed updates earlier.
        $operations[] = ['\Drush\Commands\core\UpdateDBCommands::cacheRebuild', []];
      }
      foreach ($post_updates as $function) {
        $operations[] = ['\Drush\Commands\core\UpdateDBCommands::updateDoOnePostUpdate', [$function]];
      }
    }

    $original_maint_mode = \Drupal::service('state')->get('system.maintenance_mode');
    if (!$original_maint_mode) {
      \Drupal::service('state')->set('system.maintenance_mode', true);
      $operations[] = ['\Drush\Commands\core\UpdateDBCommands::restoreMaintMode', [false]];
    }

    $batch['operations'] = $operations;
    $batch += [
      'title' => 'Updating',
      'init_message' => 'Starting updates',
      'error_message' => 'An unrecoverable error has occurred. You can find the error message below. It is advised to copy it to the clipboard for reference.',
      'finished' => [$this, 'updateFinished'],
      'file' => 'core/includes/update.inc',
    ];
    batch_set($batch);
    $result = drush_backend_batch_process(self::BATCH_PROCESS);

    $success = false;
    if (!is_array($result)) {
      $this->logger()->error(dt('Batch process did not return a result array. Returned: !type', ['!type' => gettype($result)]));
    } elseif (!empty($result[0]['#abort'])) {
      // Whenever an error occurs the batch process does not continue, so
      // this array should only contain a single item, but we still output
      // all available data for completeness.
      $this->logger()->error(dt('Update aborted by: !process', [
        '!process' => implode(', ', $result[0]['#abort']),
      ]));
    } else {
      $success = true;
    }

    return $success;
  }

  public static function restoreMaintMode($status): void
  {
    \Drupal::service('state')->set('system.maintenance_mode', $status);
  }

  // Copy of protected \Drupal\system\Controller\DbUpdateController::getModuleUpdates.
  public function getUpdateList(): array
  {
    $return = [];
    $updates = update_get_update_list();
    foreach ($updates as $module => $update) {
      if (!empty($update['start'])) {
        $return[$module] = $update['start'];
      }
    }

    return $return;
  }

  /**
   * Clears caches and rebuilds the container.
   *
   * This is called in between regular updates and post updates. Do not use
   * drush_drupal_cache_clear_all() as the cache clearing and container rebuild
   * must happen in the same process that the updates are run in.
   *
   * Drupal core's update.php uses drupal_flush_all_caches() directly without
   * explicitly rebuilding the container as the container is rebuilt on the next
   * HTTP request of the batch.
   *
   * @see \Drupal\system\Controller\DbUpdateController::triggerBatch()
   */
  public static function cacheRebuild(): void
  {
    drupal_flush_all_caches();
    \Drupal::service('kernel')->rebuildContainer();
    // Load the module data which has been removed when the container was
    // rebuilt.
    $module_handler = \Drupal::moduleHandler();
    $module_handler->loadAll();
    $module_handler->invokeAll('rebuild');
  }

  /**
   * Returns information about available module updates.
   *
   * @return array
   *   An indexed array (aka tuple) with 3 elements:
   *  - An array where each item is a 4 item associative array describing a
   *    pending update.
   *  - An array listing the first update to run, keyed by module.
   *  - An array listing the available warnings, keyed by module.
   */
  public function getUpdatedbStatus(array $options): array
  {
    require_once DRUPAL_ROOT . '/core/includes/update.inc';
    $pending = \update_get_update_list();

    $return = [];
    $warnings = [];

    // Ensure system module's updates run first.
    $start['system'] = [];

    foreach ($pending as $module => $updates) {
      if (isset($updates['start'])) {
        $start[$module] = $updates['start'];
        foreach ($updates['pending'] as $update_id => $description) {
          // Strip cruft from front.
          $description = str_replace($update_id . ' -   ', '', $description);
          $return[$module . "_update_$update_id"] = [
            'module' => $module,
            'update_id' => $update_id,
            'description' => $description,
            'type' => 'hook_update_n'
          ];
        }
      }
      if (isset($updates['warning'])) {
        $warnings[$module] = $updates['warning'];
      }
    }

    // Pending hook_post_update_X() implementations.
    /** @var UpdateRegistry $post_update_registry */
    $post_update_registry = \Drupal::service('update.post_update_registry');
    $post_updates = $post_update_registry->getPendingUpdateInformation();
    foreach ($post_updates as $module => $post_update) {
      foreach ($post_update as $key => $list) {
        if ($key == 'pending') {
          foreach ($list as $id => $item) {
            $return[$module . '-post-' . $id] = [
              'module' => $module,
              'update_id' => $id,
              'description' => trim($item),
              'type' => 'post-update'
            ];
          }
        }
      }
    }

    return [$return, $start, $warnings];
  }

  /**
   * Log messages for any requirements warnings/errors.
   */
  public function updateCheckRequirements(): bool
  {
    $return = true;

    \Drupal::moduleHandler()->resetImplementations();
    $requirements = update_check_requirements();
    $severity = drupal_requirements_severity($requirements);

    // If there are issues, report them.
    if ($severity != REQUIREMENT_OK) {
      if ($severity === REQUIREMENT_ERROR) {
        $return = false;
      }
      foreach ($requirements as $requirement) {
        if (isset($requirement['severity']) && $requirement['severity'] != REQUIREMENT_OK) {
          $message = isset($requirement['description']) ? DrupalUtil::drushRender($requirement['description']) : '';
          if (isset($requirement['value']) && $requirement['value']) {
            $message .= ' (Currently using ' . $requirement['title'] . ' ' . DrupalUtil::drushRender($requirement['value']) . ')';
          }
          $log_level = $requirement['severity'] === REQUIREMENT_ERROR ? LogLevel::ERROR : LogLevel::WARNING;
          $this->logger()->log($log_level, $message);
        }
      }
    }

    return $return;
  }

}
