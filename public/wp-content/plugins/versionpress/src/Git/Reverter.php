<?php

namespace VersionPress\Git;

use Committer;
use Nette\Utils\Strings;
use VersionPress\ChangeInfos\ChangeInfoMatcher;
use VersionPress\ChangeInfos\EntityChangeInfo;
use VersionPress\ChangeInfos\RevertChangeInfo;
use VersionPress\ChangeInfos\UntrackedChangeInfo;
use VersionPress\Database\DbSchemaInfo;
use VersionPress\Storages\StorageFactory;
use VersionPress\Synchronizers\SynchronizationProcess;
use VersionPress\Utils\ArrayUtils;
use VersionPress\Utils\ReferenceUtils;
use wpdb;

class Reverter {

    private $synchronizationProcess;

    private $database;

    private $committer;

    private $repository;

    private $dbSchemaInfo;

    private $storageFactory;

    const DELETE_ORPHANED_POSTS_SECONDS = 60;

    public function __construct(SynchronizationProcess $synchronizationProcess, $wpdb, Committer $committer, GitRepository $repository, DbSchemaInfo $dbSchemaInfo, StorageFactory $storageFactory) {
        $this->synchronizationProcess = $synchronizationProcess;
        $this->database = $wpdb;
        $this->committer = $committer;
        $this->repository = $repository;
        $this->dbSchemaInfo = $dbSchemaInfo;
        $this->storageFactory = $storageFactory;
    }

    public function undo($commitHash) {
        return $this->_revert($commitHash, "undo");
    }

    public function rollback($commitHash) {
        return $this->_revert($commitHash, "rollback");
    }

    public function canRevert() {
        if(!$this->repository->isCleanWorkingDirectory()) {
            $this->clearOrphanedPosts();
        }
        return $this->repository->isCleanWorkingDirectory();
    }

    private function _revert($commitHash, $method) {
        if (!$this->canRevert()) {
            return RevertStatus::NOT_CLEAN_WORKING_DIRECTORY;
        }

        $commitHashForDiff = $method === "undo" ? sprintf("%s~1..%s", $commitHash, $commitHash) : $commitHash;
        $modifiedFiles = $this->repository->getModifiedFiles($commitHashForDiff);
        $vpIdsInModifiedFiles = $this->getAllVpIdsFromModifiedFiles($modifiedFiles);

        if ($method === "undo") {
            $status = $this->revertOneCommit($commitHash);
            $changeInfo = new RevertChangeInfo(RevertChangeInfo::ACTION_UNDO, $commitHash);

        } else {
            $status = $this->revertToCommit($commitHash);
            $changeInfo = new RevertChangeInfo(RevertChangeInfo::ACTION_ROLLBACK, $commitHash);
        }

        if ($status !== RevertStatus::OK) {
            return $status;
        }

        $this->committer->forceChangeInfo($changeInfo);
        $this->committer->commit();

        $vpIdsInModifiedFiles = array_merge($vpIdsInModifiedFiles, $this->getAllVpIdsFromModifiedFiles($modifiedFiles));
        $vpIdsInModifiedFiles = array_unique($vpIdsInModifiedFiles, SORT_REGULAR);

        $entitiesToSynchronize = $this->detectEntitiesToSynchronize($modifiedFiles, $vpIdsInModifiedFiles);

        $this->synchronizationProcess->synchronize($entitiesToSynchronize);
        $affectedPosts = $this->getAffectedPosts($modifiedFiles);
        $this->updateChangeDateForPosts($affectedPosts);

        return RevertStatus::OK;
    }

    private function updateChangeDateForPosts($vpIds) {
        $date = current_time('mysql');
        $dateGmt = current_time('mysql', true);
        foreach ($vpIds as $vpId) {
            $sql = "update {$this->database->prefix}posts set post_modified = '{$date}', post_modified_gmt = '{$dateGmt}' where ID = (select id from {$this->database->prefix}vp_id where vp_id = unhex('{$vpId}'))";
            $this->database->query($sql);
        }
    }

    private function getAffectedPosts($modifiedFiles) {
        $posts = array();

        foreach ($modifiedFiles as $filename) {
            $match = Strings::match($filename, '~/posts/.*/(.*)\.ini~');
            if ($match) {
                $posts[] = $match[1];
            }
        }

        return $posts;
    }

    private function checkReferencesForRevertedCommit(Commit $revertedCommit) {
        $changeInfo = ChangeInfoMatcher::buildChangeInfo($revertedCommit->getMessage());

        if ($changeInfo instanceof UntrackedChangeInfo) {
            return true;
        }

        foreach ($changeInfo->getChangeInfoList() as $subChangeInfo) {
            if ($subChangeInfo instanceof EntityChangeInfo &&
                !$this->checkEntityReferences($subChangeInfo->getEntityName(), $subChangeInfo->getEntityId(), $subChangeInfo->getParentId())) {
                return false;
            }
        }

        return true;
    }

    private function checkEntityReferences($entityName, $entityId, $parentId) {
        $entityInfo = $this->dbSchemaInfo->getEntityInfo($entityName);
        $storage = $this->storageFactory->getStorage($entityName);

        if (!$storage->exists($entityId, $parentId)) {
            return !$this->existsSomeEntityWithReferenceTo($entityName, $entityId);
        }

        $entity = $storage->loadEntity($entityId, $parentId);

        foreach ($entityInfo->references as $reference => $referencedEntityName) {
            $vpReference = "vp_$reference";
            if (!isset($entity[$vpReference])) {
                continue;
            }

            $entityExists = $this->storageFactory->getStorage($referencedEntityName)->exists($entity[$vpReference], $parentId);
            if (!$entityExists) {
                return false;
            }

        }

        foreach ($entityInfo->mnReferences as $reference => $referencedEntityName) {
            $vpReference = "vp_$referencedEntityName";
            if (!isset($entity[$vpReference])) {
                continue;
            }

            foreach ($entity[$vpReference] as $referencedEntityId) {
                $entityExists = $this->storageFactory->getStorage($referencedEntityName)->exists($referencedEntityId, $parentId);
                if (!$entityExists) {
                    return false;
                }
            }
        }

        foreach ($entityInfo->valueReferences as $reference => $referencedEntityName) {
            list($sourceColumn, $sourceValue, $valueColumn) = array_values(ReferenceUtils::getValueReferenceDetails($reference));
            if (!isset($entity[$sourceColumn]) || $entity[$sourceColumn] != $sourceValue || !isset($entity[$valueColumn])) {
                continue;
            }

            if ($referencedEntityName[0] === '@') {
                $entityNameProvider = substr($referencedEntityName, 1); 
                $referencedEntityName = call_user_func($entityNameProvider, $entity);
                if (!$referencedEntityName) {
                    continue;
                }
            }

            $entityExists = $this->storageFactory->getStorage($referencedEntityName)->exists($entity[$valueColumn], $parentId);
            if (!$entityExists) {
                return false;
            }
        }

        return true;
    }

    private function existsSomeEntityWithReferenceTo($entityName, $entityId) {
        $entityNames = $this->dbSchemaInfo->getAllEntityNames();

        foreach ($entityNames as $otherEntityName) {
            $otherEntityInfo = $this->dbSchemaInfo->getEntityInfo($otherEntityName);
            $otherEntityReferences = $otherEntityInfo->references;
            $otherEntityMnReferences = $otherEntityInfo->mnReferences;
            $otherEntityValueReferences = $otherEntityInfo->valueReferences;

            $allReferences = array_merge($otherEntityReferences, $otherEntityMnReferences, $otherEntityValueReferences);

            $reference = array_search($entityName, $allReferences);

            if ($reference === false) { 
                continue;
            }

            $otherEntityStorage = $this->storageFactory->getStorage($otherEntityName);
            $possiblyReferencingEntities = $otherEntityStorage->loadAll();

            if (isset($otherEntityReferences[$reference])) { 
                $vpReference = "vp_$reference";

                foreach ($possiblyReferencingEntities as $possiblyReferencingEntity) {
                    if (isset($possiblyReferencingEntity[$vpReference]) && $possiblyReferencingEntity[$vpReference] === $entityId) {
                        return true;
                    }
                }
            } elseif (isset($otherEntityMnReferences[$reference])) { 
                $vpReference = "vp_$otherEntityName";
                foreach ($possiblyReferencingEntities as $possiblyReferencingEntity) {
                    if (isset($possiblyReferencingEntity[$vpReference]) && array_search($entityId, $possiblyReferencingEntity[$vpReference]) !== false) {
                        return true;
                    }
                }
            } elseif (isset($otherEntityValueReferences[$reference])) { 
                list($sourceColumn, $sourceValue, $valueColumn) = array_values(ReferenceUtils::getValueReferenceDetails($reference));

                foreach ($possiblyReferencingEntities as $possiblyReferencingEntity) {
                    if (isset($possiblyReferencingEntity[$sourceColumn]) && $possiblyReferencingEntity[$sourceColumn] == $sourceValue && isset($possiblyReferencingEntity[$valueColumn]) && $possiblyReferencingEntity[$valueColumn] === $entityId) {
                        return true;
                    }
                }
            }

        }

        return false;
    }

    private function detectEntitiesToSynchronize($modifiedFiles, $vpIdsInModifiedFiles) {
        $entitiesToSynchronize = array('storages' => array(), 'entities' => $vpIdsInModifiedFiles);

        if ($this->wasModified($modifiedFiles, 'terms.ini')) {
            $entitiesToSynchronize['storages'][] = 'term';
            $entitiesToSynchronize[] = 'term_taxonomy';
        }

        if ($this->wasModified($modifiedFiles, 'options.ini')) {
            $entitiesToSynchronize['storages'][] = 'option';
        }

        return $entitiesToSynchronize;
    }

    private function wasModified($modifiedFiles, $pathPart) {
        return ArrayUtils::any($modifiedFiles, function ($file) use ($pathPart) {
            return Strings::contains($file, $pathPart);
        });
    }

    private function getAllVpIdsFromModifiedFiles($modifiedFiles) {
        $vpIds = array();
        $vpIdRegex = "/([\\da-f]{32})/i";

        foreach ($modifiedFiles as $file) {
            if (!is_file(ABSPATH . $file)) {
                continue;
            }

            preg_match($vpIdRegex, $file, $matches);
            

            $parent = @$matches[0];

            $content = file_get_contents(ABSPATH . $file);
            preg_match_all($vpIdRegex, $content, $matches);
            $vpIds = array_merge($vpIds, array_map(function ($vpId) use ($parent) { return array('vp_id' => $vpId, 'parent' => $parent); }, $matches[0]));
        }
        return $vpIds;
    }

    private function revertOneCommit($commitHash) {
        $commit = $this->repository->getCommit($commitHash);
        if ($commit->isMerge()) {
            return RevertStatus::REVERTING_MERGE_COMMIT;
        }
        if (!$this->repository->revert($commitHash)) {
            return RevertStatus::MERGE_CONFLICT;
        }

        $revertedCommit = $this->repository->getCommit($commitHash);
        $referencesOk = $this->checkReferencesForRevertedCommit($revertedCommit);

        if (!$referencesOk) {
            $this->repository->abortRevert();
            return RevertStatus::VIOLATED_REFERENTIAL_INTEGRITY;
        }

        return RevertStatus::OK;
    }

    private function revertToCommit($commitHash) {
        $this->repository->revertAll($commitHash);

        if (!$this->repository->willCommit()) {
            return RevertStatus::NOTHING_TO_COMMIT;
        }

        return RevertStatus::OK;
    }

    private function clearOrphanedPosts() {
        $deleteTimestamp = time() - self::DELETE_ORPHANED_POSTS_SECONDS; 
        $wpdb = $this->database;
        $orphanedMenuItems = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts AS p LEFT JOIN $wpdb->postmeta AS m ON p.ID = m.post_id WHERE post_type = 'nav_menu_item' AND post_status = 'draft' AND meta_key = '_menu_item_orphaned' AND meta_value < '%d'", $deleteTimestamp ) );

        foreach( (array) $orphanedMenuItems as $menuItemId ) {
            wp_delete_post($menuItemId, true);
            $this->committer->discardPostponedCommit('menu-item-' . $menuItemId);
        }
    }
}
