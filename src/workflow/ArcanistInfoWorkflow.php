<?php

/**
 * Gets various internal info.
 */
final class ArcanistInfoWorkflow extends ArcanistWorkflow {

  const SOURCE_BUNDLE         = 'bundle';
  const SOURCE_REVISION       = 'revision';
  const SOURCE_DIFF           = 'diff';

  private $source;
  private $sourceParam;

  public function getWorkflowName() {
    return 'info';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **info** __D12345__
      **info** __--revision__ __revision_id__
      **info** __--diff__ __diff_id__
      **info** __--arcbundle__ __bundlefile__
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, svn, hg
          Get various internal info.
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'revision' => array(
        'param' => 'revision_id',
        'paramtype' => 'complete',
        'help' => pht(
          "Get info from a Differential revision, using the most recent ".
          "diff that has been attached to it. You can run '%s' as a shorthand.",
          'arc info D12345'),
      ),
      'diff' => array(
        'param' => 'diff_id',
        'help' => pht(
          'Get info from a Differential diff. Normally you want to use %s to '.
          'get the most recent changes, but you can specifically get info on '.
          'an out-of-date diff or a diff which was never attached to a '.
          'revision by using this flag.',
          '--revision'),
      ),
      'arcbundle' => array(
        'param' => 'bundlefile',
        'paramtype' => 'file',
        'help' => pht(
          "Get info from an arc bundle generated with '%s'.",
          'arc export'),
      ),
      'encoding' => array(
        'param' => 'encoding',
        'help' => pht(
          'Attempt to convert non UTF-8 patch into specified encoding.'),
      ),
      'base-commit' => array(
        'supports' => array('git', 'hg'),
        'help' => pht(
          'Get the base commit.'),
      ),
      'skip-dependencies' => array(
        'supports' => array('git', 'hg'),
        'help' => pht(
          'Don\'t follow dependencies.'),
      ),
      '*' => 'name',
    );
  }

  protected function didParseArguments() {
    $arguments = array(
      'revision' => self::SOURCE_REVISION,
      'diff' => self::SOURCE_DIFF,
      'arcbundle' => self::SOURCE_BUNDLE,
      'name' => self::SOURCE_REVISION,
    );

    $sources = array();
    foreach ($arguments as $key => $source_type) {
      $value = $this->getArgument($key);
      if (!$value) {
        continue;
      }

      switch ($key) {
        case 'revision':
          $value = $this->normalizeRevisionID($value);
          break;
        case 'name':
          if (count($value) > 1) {
            throw new ArcanistUsageException(
              pht('Specify at most one revision name.'));
          }
          $value = $this->normalizeRevisionID(head($value));
          break;
      }

      $sources[] = array(
        $source_type,
        $value,
      );
    }

    if (!$sources) {
      throw new ArcanistUsageException(
        pht(
          'You must specify changes to apply to the working copy with '.
          '"D12345", "--revision", "--diff", or "--arcbundle".'));
    }

    if (count($sources) > 1) {
      throw new ArcanistUsageException(
        pht(
          'Options "D12345", "--revision", "--diff", and "--arcbundle" '.
          'are mutually exclusive. Choose exactly one info '.
          'source.'));
    }

    $source = head($sources);

    $this->source = $source[0];
    $this->sourceParam = $source[1];
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function requiresWorkingCopy() {
    return true;
  }

  private function getSource() {
    return $this->source;
  }

  private function getSourceParam() {
    return $this->sourceParam;
  }

  private function shouldGetBaseCommit() {
    return $this->getArgument('base-commit', false);
  }

  private function shouldFollowDependencies() {
    return !$this->getArgument('skip-dependencies', false);
  }

  public function run() {
    $source = $this->getSource();
    $param = $this->getSourceParam();
    try {
      switch ($source) {
        case self::SOURCE_BUNDLE:
          $path = $this->getArgument('arcbundle');
          $bundle = ArcanistBundle::newFromArcBundle($path);
          break;
        case self::SOURCE_REVISION:
          $bundle = $this->loadRevisionBundleFromConduit(
            $this->getConduit(),
            $param);
          break;
        case self::SOURCE_DIFF:
          $bundle = $this->loadDiffBundleFromConduit(
            $this->getConduit(),
            $param);
          break;
      }
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-INVALID-SESSION') {
        // Phabricator is not configured to allow anonymous access to
        // Differential.
        $this->authenticateConduit();
        return $this->run();
      } else {
        throw $ex;
      }
    }

    $try_encoding = nonempty($this->getArgument('encoding'), null);
    if (!$try_encoding) {
      if ($this->requiresConduit()) {
        try {
          $try_encoding = $this->getRepositoryEncoding();
        } catch (ConduitClientException $e) {
          $try_encoding = null;
        }
      }
    }

    if ($try_encoding) {
      $bundle->setEncoding($try_encoding);
    }

    if ($this->shouldGetBaseCommit()) {
      $exit_code = 1;
      if ($this->shouldFollowDependencies() &&
          $this->getFirstDependency($bundle, $exit_code)) {
        return $exit_code;
      }

      $repository_api = $this->getRepositoryAPI();
      $has_base_revision = $repository_api->hasLocalCommit(
        $bundle->getBaseRevision());
      if (!$has_base_revision) {
        if ($repository_api instanceof ArcanistGitAPI) {
          echo phutil_console_format(
            "<bg:blue>** %s **</bg> %s\n",
            pht('INFO'),
            pht('Base commit is not in local repository; trying to fetch.'));
          $repository_api->execManualLocal('fetch --quiet --all');
          $has_base_revision = $repository_api->hasLocalCommit(
            $bundle->getBaseRevision());
        }
        if (!$has_base_revision) {
          return 3;
        }
      }

      print($bundle->getBaseRevision());
    }

    return 0;
  }

  protected function getShellCompletions(array $argv) {
    // TODO: Pull open diffs from 'arc list'?
    return array('ARGUMENT');
  }

  private function getFirstDependency(ArcanistBundle $bundle, $exit_code) {
    // check for any dependent revisions, and run on the first dependency found
    $graph = $this->buildDependencyGraph($bundle);
    if ($graph) {
      $start_phid = $graph->getStartPHID();
      $cycle_phids = $graph->detectCycles($start_phid);
      if ($cycle_phids) {
        throw new Exception(
          "dependencies form cycle; can't find first dependency");
      } else {
        $phids = $graph->getNodesInTopologicalOrder();
        $phids = array_reverse($phids);
        $okay = true;
      }

      if (!$okay) {
        return;
      }

      $dep_on_revs = $this->getConduit()->callMethodSynchronous(
        'differential.query',
        array(
          'phids' => $phids,
        ));
      $revs = array();
      foreach ($dep_on_revs as $dep_on_rev) {
        $revs[$dep_on_rev['phid']] = 'D'.$dep_on_rev['id'];
      }
      // order them in case we got a topological sort earlier
      $revs = array_select_keys($revs, $phids);
      if (!empty($revs)) {
        $base_args = array(
          '--base-commit',
          '--skip-dependencies',
        );

        foreach ($revs as $phid => $diff_id) {
          $args = $base_args;
          $args[] = $diff_id;
          $apply_workflow = $this->buildChildWorkflow(
            'info',
            $args);
          $exit_code = $apply_workflow->run();
          return true;
        }
      }
    }
    return false;
  }

  private function buildDependencyGraph(ArcanistBundle $bundle) {
    $graph = null;
    if ($this->getRepositoryAPI() instanceof ArcanistSubversionAPI) {
      return $graph;
    }
    $revision_id = $bundle->getRevisionID();
    if ($revision_id) {
      $revisions = $this->getConduit()->callMethodSynchronous(
        'differential.query',
        array(
          'ids' => array($revision_id),
        ));
      if ($revisions) {
        $revision = head($revisions);
        $rev_auxiliary = idx($revision, 'auxiliary', array());
        $phids = idx($rev_auxiliary, 'phabricator:depends-on', array());
        if ($phids) {
          $revision_phid = $revision['phid'];
          $graph = id(new ArcanistDifferentialDependencyGraph())
            ->setConduit($this->getConduit())
            ->setRepositoryAPI($this->getRepositoryAPI())
            ->setStartPHID($revision_phid)
            ->addNodes(array($revision_phid => $phids))
            ->loadGraph();
        }
      }
    }

    return $graph;
  }

}
