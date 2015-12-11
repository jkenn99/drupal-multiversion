<?php

namespace Drupal\multiversion\Entity\Storage;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\multiversion\Entity\Exception\ConflictException;
use Drupal\user\UserStorageInterface;

trait ContentEntityStorageTrait {

  /**
   * @var boolean
   */
  protected $isDeleted = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getQueryServiceName() {
    return 'entity.query.sql.multiversion';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQuery($ids, $revision_id = FALSE) {
    $query = parent::buildQuery($ids, $revision_id);

    // Prevent to modify the query before entity type updates.
    if ($ids === NULL) {
      return $query;
    }

    $revision_alias = 'revision';
    $workspace_alias = 'workspace';
    if ($this->entityType->isTranslatable()) {
      // Join the revision data table in order to set the delete condition.
      $revision_table = $this->getRevisionDataTable();
      $revision_alias = 'revision_data';
      if ($revision_id) {
        $query->join($revision_table, $revision_alias, "$revision_alias.{$this->revisionKey} = revision.{$this->revisionKey} AND $revision_alias.{$this->revisionKey} = :revisionId", array(':revisionId' => $revision_id));
      }
      else {
        $query->join($revision_table, $revision_alias, "$revision_alias.{$this->revisionKey} = revision.{$this->revisionKey}");
      }
    }
    // Loading a revision is explicit. So when we try to load one we should do
    // so without a condition on the deleted flag.
    if (!$revision_id) {
      $query->condition("$revision_alias._deleted", (int) $this->isDeleted);
    }
    // Entities in other workspaces than the active one can only be queried with
    // the Entity Query API and not by the storage handler itself.
    // Just UserStorage can be queried in all workspaces by the storage handler.
    if (!$this instanceof UserStorageInterface) {
      /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $table_mapping */
      $table_mapping = $this->getTableMapping();
      /** @var \Drupal\Core\Entity\EntityFieldManager $field_manager */
      $field_manager = \Drupal::service('entity_field.manager');
      $base_definitions = $field_manager->getBaseFieldDefinitions($this->entityTypeId);

      $workspace_definition = $base_definitions['workspace'];
      $workspace_table = $table_mapping->getDedicatedRevisionTableName($workspace_definition);
      $workspace_column = $table_mapping->getFieldColumnName($workspace_definition, 'target_id');

      if ($revision_id) {
        $query->join($workspace_table, $workspace_alias, "$workspace_alias.revision_id = revision.{$this->revisionKey} AND $workspace_alias.revision_id = :revisionId", array(':revisionId' => $revision_id));
      }
      else {
        $query->join($workspace_table, $workspace_alias, "$workspace_alias.revision_id = revision.{$this->revisionKey}");
      }
      $query->condition("$workspace_alias.$workspace_column", $this->getActiveWorkspaceId());
    }
    return $query;
  }

  /**
   * Helper method to get the active workspace ID.
   */
  protected function getActiveWorkspaceId() {
    return \Drupal::service('workspace.manager')->getActiveWorkspace()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function loadUnchanged($id) {
    $this->resetCache(array($id));
    return $this->load($id) ?: $this->loadDeleted($id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = NULL) {
    $this->isDeleted = FALSE;
    return parent::loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function loadDeleted($id) {
    $entities = $this->loadMultipleDeleted(array($id));
    return isset($entities[$id]) ? $entities[$id] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultipleDeleted(array $ids = NULL) {
    $this->isDeleted = TRUE;
    return parent::loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    // Every update is a new revision with this storage model.
    $entity->setNewRevision();

    // We are going to index the revision ahead of save in order to accurately
    // determine if this is going to be the default revision or not. We also run
    // this logic here outside of any transactions that the parent storage
    // handler might perform. It's important that the revision index does not
    // get rolled back during exceptions. All records are kept in order to more
    // accurately build revision trees of all universally known revisions.
    $branch = array();
    $rev = $entity->_rev->value;
    list($i) = explode('-', $rev);

    // This is a regular local save operation and a new revision token should be
    // generated. The new_edit property will be set to FALSE during replication
    // to ensure the revision token is saved as-is.
    if ($entity->_rev->new_edit || $entity->_rev->is_stub) {
      // If this is the first revision it means that there's no parent.
      // By definition the existing revision value is the parent revision.
      $parent_rev = $i == 0 ? 0 : $rev;
      // Only generate a new revision if this is not a stub entity. This will
      // ensure that stub entities remain with the default value (0) to make it
      // clear on a storage level that this is a stub and not a "real" revision.
      if (!$entity->_rev->is_stub) {
        $rev = \Drupal::service('multiversion.manager')->newRevisionId($entity, $i);
      }
      list(, $hash) = explode('-', $rev);
      $entity->_rev->value = $rev;
      $entity->_rev->revisions = array($hash);
      $branch[$rev] = $parent_rev;

      // Add the parent revision to list of known revisions. This will be useful
      // if an exception is thrown during entity save and a new attempt is made.
      if ($parent_rev != 0) {
        list(, $parent_hash) = explode('-', $parent_rev);
        $entity->_rev->revisions = array($hash, $parent_hash);
      }
    }
    // A list of all known revisions can be passed in to let the current host
    // know about the revision history, for conflict handling etc. A list of
    // revisions are always passed in during replication.
    else {
      $revisions = $entity->_rev->revisions;
      for ($c = 0; $c < count($revisions); ++$c) {
        $p = $c + 1;
        $rev = $i-- . '-' . $revisions[$c];
        $parent_rev = isset($revisions[$p]) ? $i . '-' . $revisions[$p] : 0;
        $branch[$rev] = $parent_rev;
      }
    }

    // Index the revision info and tree.
    \Drupal::service('entity.index.rev')->add($entity);
    \Drupal::service('entity.index.rev.tree')->updateTree($entity->uuid(), $branch);

    try {
      return parent::save($entity);
    }
    catch (\Exception $e) {
      // If a new attempt at saving the entity is made after an exception its
      // important that a new rev token is not generated.
      $entity->_rev->new_edit = FALSE;
      throw new EntityStorageException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @todo Revisit this logic with forward revisions in mind.
   */
  protected function doSave($id, EntityInterface $entity) {
    if ($entity->_rev->is_stub) {
      $entity->isDefaultRevision(TRUE);
    }
    else {
      // Enforce new revision if any module messed with it in a hook.
      $entity->setNewRevision();

      // Decide whether or not this is the default revision.
      if (!$entity->isNew()) {
        $default_rev = \Drupal::service('entity.index.rev.tree')->getDefaultRevision($entity->uuid());
        if ($entity->_rev->value == $default_rev) {
          $entity->isDefaultRevision(TRUE);
        }
        // @todo: {@link https://www.drupal.org/node/2597538 Needs test.}
        else {
          $entity->isDefaultRevision(FALSE);
        }
      }
    }

    return parent::doSave($id, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    // Ensure that the entities are keyed by ID.
    $keyed_entities = [];
    foreach ($entities as $entity) {
      $keyed_entities[$entity->id()] = $entity;
    }

    // Entities are always "deleted" as new revisions when using a Multiversion
    // storage handler.
    foreach ($entities as $entity) {
      $entity->_deleted->value = TRUE;
      $this->save($entity);
    }

    // Reset the static cache for the "deleted" entities.
    $this->resetCache(array_keys($keyed_entities));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision($revision_id) {
    // Do nothing by design.
  }

  /**
   * {@inheritdoc}
   */
  public function purge(array $entities) {
    parent::delete($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(array $ids = NULL) {
    parent::resetCache($ids);
    $ws = $this->getActiveWorkspaceId();
    if ($this->entityType->isStaticallyCacheable() && isset($ids)) {
      foreach ($ids as $id) {
        unset($this->entities[$ws][$id]);
      }
    }
    else {
      $this->entities[$ws] = array();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getFromStaticCache(array $ids) {
    $ws = $this->getActiveWorkspaceId();
    $entities = array();
    // Load any available entities from the internal cache.
    if ($this->entityType->isStaticallyCacheable() && !empty($this->entities[$ws])) {
      $entities += array_intersect_key($this->entities[$ws], array_flip($ids));
    }
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  protected function setStaticCache(array $entities) {
    if ($this->entityType->isStaticallyCacheable()) {
      $ws = $this->getActiveWorkspaceId();
      if (!isset($this->entities[$ws])) {
        $this->entities[$ws] = array();
      }
      $this->entities[$ws] += $entities;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCacheId($id) {
    $ws = $this->getActiveWorkspaceId();
    return "values:{$this->entityTypeId}:$id:$ws";
  }

  /**
   * {@inheritdoc}
   */
  protected function setPersistentCache($entities) {
    if (!$this->entityType->isPersistentlyCacheable()) {
      return;
    }
    $ws = $this->getActiveWorkspaceId();
    $cache_tags = array(
      $this->entityTypeId . '_values',
      'entity_field_info',
      'workspace_' . $ws,
    );
    foreach ($entities as $id => $entity) {
      $this->cacheBackend->set($this->buildCacheId($id), $entity, CacheBackendInterface::CACHE_PERMANENT, $cache_tags);
    }
  }

}
