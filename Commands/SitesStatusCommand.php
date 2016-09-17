<?php

namespace Terminus\Commands;

use Terminus\Collections\Sites;
use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Organization;
use Terminus\Models\Site;
use Terminus\Models\Upstreams;
use Terminus\Models\User;
use Terminus\Models\Workflow;
use Terminus\Session;

/**
 * Actions on multiple sites
 *
 * @command sites
 */
class SitesStatusCommand extends TerminusCommand {
  public $sites;

  /**
   * Report the status of all available sites
   *
   * @param array $options Options to construct the command object
   * @return SitesStatusCommand
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
  }

  /**
   * Report the status of all available sites
   * Note: because of the size of this call, it is cached
   *   and also is the basis for loading individual sites by name
   *
   * [--env=<env>]
   * : Filter sites by environment.
   *
   * [--team]
   * : Filter for sites you are a team member of
   *
   * [--owner]
   * : Filter for sites a specific user owns. Use "me" for your own user.
   *
   * [--org=<id>]
   * : Filter sites you can access via the organization. Use 'all' to get all.
   *
   * [--name=<regex>]
   * : Filter sites you can access via name
   *
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew
   *
   * @param array $args       Array of arguments
   * @param array $assoc_args Array of associative arguments
   *
   * @subcommand status
   * @alias st
   *
   * @return null
   */
  public function status($args, $assoc_args) {
    $options = [
      'org_id'    => $this->input()->optional(
        [
          'choices' => $assoc_args,
          'default' => null,
          'key'     => 'org',
        ]
      ),
      'team_only' => isset($assoc_args['team']),
    ];
    $this->sites->fetch($options);

    if (isset($assoc_args['name'])) {
      $this->sites->filterByName($assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = $this->user->id;
      }
      $this->sites->filterByOwner($owner_uuid);
    }

    $sites = $this->sites->all();

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = 'all';
    if (isset($assoc_args['env'])) {
      $env = $assoc_args['env'];
    }
    $valid_env = ($env == 'all');
    if (!$valid_env) {
      foreach ($sites as $site) {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $e = $environment->get('id');
          if ($e == $env) {
            $valid_env = true;
            break;
          }
        }
        if ($valid_env) {
          break;
        }
      }
    }
    if (!$valid_env) {
      $message = 'Invalid --env argument value. Allowed values are dev, test, live or a valid';
      $message .= ' multi-site environment.';
      $this->failure($message);
    }

    $site_rows = array();
    $site_labels = [
      'name'            => 'Name',
      'service_level'   => 'Service',
      'framework'       => 'Framework',
      'created'         => 'Created',
      'frozen'          => 'Frozen',
      'newrelic'        => 'New Relic',
    ];

    $env_rows = array();
    $env_labels = [
      'name'            => 'Name',
      'environment'     => 'Env',
      'php_version'     => 'PHP',
      'drush_version'   => 'Drush',
      'redis'           => 'Redis',
      'solr'            => 'Solr',
      'connection_mode' => 'Mode',
      'condition'       => 'Condition',
    ];

    // Loop through each site and collect status data.
    foreach ($sites as $site) {
      $name = $site->get('name');

      if (!is_null($site->get('frozen'))) {
        $frozen = 'yes';
      } else {
        $frozen = 'no';
      }

      if (!is_null($site->newrelic())) {
        $newrelic = 'enabled';
      } else {
        $newrelic = 'disabled';
      }

      $site_rows[] = [
        'name'          => $name,
        'service_level' => $site->get('service_level'),
        'framework'     => $site->get('framework'),
        'created'       => date('d M Y h:i A', $site->get('created')),
        'frozen'        => $frozen,
        'newrelic'      => $newrelic,
      ];

      // Loop through each environment.
      if ($env == 'all') {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $args = array(
            'name'    => $name,
            'env'     => $environment->get('id'),
          );
          $env_rows = $this->getStatus($args, $env_rows);
        }
      } else {
        $args = array(
          'name'    => $name,
          'env'     => $env,
        );
        $env_rows = $this->getStatus($args, $env_rows);
      }
    }

    // Output the status data in table format.
    $this->output()->outputRecordList($site_rows, $site_labels);
    $this->output()->outputRecordList($env_rows, $env_labels);

  }

  /**
   * Collect the status data of a specific site and environment.
   *
   * @param array $args     The site environment arguments.
   * @param array $env_rows The site environment status data.
   *
   * @return array $env_rows The site environment status data.
   */
  private function getStatus($args, $env_rows) {
    $name = $args['name'];
    $environ = $args['env'];

    $assoc_args = array(
      'site' => $name,
      'env'  => $environ,
    );

    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args])
    );

    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );

    // Determine the condition of the environment.
    $condition = 'clean';
    $connection_mode = $env->info('connection_mode');
    if ($connection_mode == 'sftp') {
      $diffstat = (array)$env->diffstat();
      if (!empty($diffstat)) {
        $condition = 'dirty';
      }
    }

    // Determine Redis and Solr status.
    $redis = 'unavailable';
    $solr = 'unavailable';
    if (!in_array($site->info('service_level'), ['free', 'basic'])) {
      $connection_info = $env->connectionInfo();
      if (isset($connection_info['redis_host'])) {
        $redis = 'enabled';
      } else {
        $redis = 'disabled';
      }
      if (isset($connection_info['solr_host'])) {
        $solr = 'enabled';
      } else {
        $solr = 'disabled';
      }
    }

    $env_rows[] = [
      'name'            => $name,
      'environment'     => $environ,
      'php_version'     => $env->info('php_version'),
      'drush_version'   => $env->getDrushVersion(),
      'redis'           => $redis,
      'solr'            => $solr,
      'connection_mode' => $connection_mode,
      'condition'       => $condition,
    ];

    return $env_rows;

  }

}
