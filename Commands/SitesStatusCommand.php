<?php

namespace Terminus\Commands;

use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Collections\Sites;
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
   * @subcommand status
   * @alias st
   */
  public function status($args, $assoc_args) {
    // Always fetch a fresh list of sites.
    if (!isset($assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }
    $sites = $this->sites->all();

    if (isset($assoc_args['team'])) {
      $sites = $this->filterByTeamMembership($sites);
    }
    if (isset($assoc_args['org'])) {
      $org_id = $this->input()->orgId(
        [
          'allow_none' => true,
          'args'       => $assoc_args,
          'default'    => 'all',
        ]
      );
      $sites = $this->filterByOrganizationalMembership($sites, $org_id);
    }

    if (isset($assoc_args['name'])) {
      $sites = $this->filterByName($sites, $assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = Session::getData()->user_uuid;
      }
      $sites = $this->filterByOwner($sites, $owner_uuid);
    }

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = isset($assoc_args['env']) ? $assoc_args['env'] : 'all';
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
      $message = 'Invalid --env argument value. Allowed values are dev, test, live or a valid multi-site environment.';
      $this->failure($message);
    }

    $site_rows = array();
    $site_labels = [
      'name'            => 'Name',
      'service_level'   => 'Service',
      'framework'       => 'Framework',
      'created'         => 'Created',
      'frozen'          => 'Frozen',
    ];

    $env_rows = array();
    $env_labels = [
      'name'            => 'Name',
      'environment'     => 'Env',
      'php_version'     => 'PHP',
      'drush_version'   => 'Drush',
      'newrelic'        => 'New Relic',
      'connection_mode' => 'Mode',
      'condition'       => 'Condition',
    ];

    // Loop through each site and collect status data.
    foreach ($sites as $site) {
      $name = $site->get('name');

      $frozen = 'no';
      if ($site->get('frozen')) {
        $frozen = 'yes';
      }

      $site_rows[] = [
        'name'            => $name,
        'service_level'   => $site->get('service_level'),
        'framework'       => $site->get('framework'),
        'created'         => date('d M Y h:i A', $site->get('created')),
        'frozen'          => $frozen,
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
      }
      else {
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
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites An array of sites to filter by
   * @param string $regex Non-delimited PHP regex to filter site names by
   * @return Site[]
   */
  private function filterByName($sites, $regex = '(.*)') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($regex) {
        preg_match("~$regex~", $site->get('name'), $matches);
        $is_match = !empty($matches);
        return $is_match;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites      An array of sites to filter by
   * @param string $owner_uuid UUID of the owning user to filter by
   * @return Site[]
   */
  private function filterByOwner($sites, $owner_uuid) {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($owner_uuid) {
        $is_owner = ($site->get('owner') == $owner_uuid);
        return $is_owner;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites  An array of sites to filter by
   * @param string $org_id ID of the organization to filter for
   * @return Site[]
   */
  private function filterByOrganizationalMembership($sites, $org_id = 'all') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($org_id) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ((($org_id == 'all') && ($membership['type'] == 'organization'))
            || ($membership['id'] === $org_id)
          ) {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is a team member
   *
   * @param Site[] $sites An array of sites to filter by
   * @return Site[]
   */
  private function filterByTeamMembership($sites) {
    $filtered_sites = array_filter(
      $sites,
      function($site) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ($membership['name'] == 'Team') {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Collect the status data of a specific site and environment.
   *
   * @param array $args
   *   The site environment arguments.
   * @param array $env_rows
   *   The site environment status data.
   * @return array $env_rows
   *   The site environment status data.
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

    $condition = 'clean';
    $connection_mode = $env->info('connection_mode');
    if ($connection_mode == 'sftp') {
      $diffstat = (array)$env->diffstat();
      if (!empty($diffstat)) {
        $condition = 'dirty';
      }
    }

    $data = $site->newRelic();
    $newrelic = !empty($data->account) ? 'enabled' : 'disabled';

    $env_rows[] = [
      'name'            => $name,
      'environment'     => $environ,
      'php_version'     => $env->info('php_version'),
      'drush_version'   => $env->getDrushVersion(),
      'newrelic'        => $newrelic,
      'connection_mode' => $connection_mode,
      'condition'       => $condition,
    ];

    return $env_rows;

  }

}
