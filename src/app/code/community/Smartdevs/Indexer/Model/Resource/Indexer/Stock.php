<?php

/**
 * CatalogInventory Stock Status Indexer Resource Model
 *
 * @category    Smartdevs
 * @package     Smartdevs_Indexer
 * @author      Daniel Niedergesäß <daniel.niedergesaess@gmail.com>
 */
class Smartdevs_Indexer_Model_Resource_Indexer_Stock extends Mage_CatalogInventory_Model_Resource_Indexer_Stock
{
    /**
     * retrieve helper instance
     *
     * @return Smartdevs_Indexer_Helper_Data
     */
    protected function getIndexerHelper()
    {
        return Mage::helper('smartdevs_indexer');
    }

    /**
     * Retrieve new index table name
     *
     * @return string
     */
    private function getNewTable()
    {
        return $this->getMainTable() . '_new';
    }

    /**
     * Retrieve old index table name
     *
     * @return string
     */
    private function getOldTable()
    {
        return $this->getMainTable() . '_old';
    }

    /**
     * Clean up temporary index table
     *
     * magento runs per default delete from table which blows up
     * the mysql transaction log
     * this method isn't required anymore
     */
    public function clearTemporaryIndexTable()
    {
        return $this;
    }

    /**
     * Synchronize data between index storage and original storage
     *
     * we use here a table rotation instead of deleting the whole table inside an transaction
     * which blows up the mysql transaction log and creates a lot of iops inside the database
     *
     * @return Mage_Index_Model_Resource_Abstract
     */
    public function syncData()
    {
        //clean up last rotation if there was an error
        $this->_getWriteAdapter()->dropTable($this->getNewTable());
        $this->_getWriteAdapter()->dropTable($this->getOldTable());

        try {
            $table = $this->_getWriteAdapter()->createTableByDdl($this->getMainTable(), $this->getNewTable());

            //foreign keys should be unique so we need to change the names of the table
            $rpForeignKeys = new ReflectionProperty($table, '_foreignKeys');
            $rpForeignKeys->setAccessible(true);
            $fkTmp = array();
            foreach ($table->getForeignKeys() as $foreignKeyData) {
                $uuid = $this->getIndexerHelper()->getUUID();
                $foreignKeyData['FK_NAME'] = $uuid;
                $fkTmp[$uuid] = $foreignKeyData;
            }
            $rpForeignKeys->setValue($table, $fkTmp);
            $this->_getWriteAdapter()->createTable($table);

            //get columns mapping and insert data to new table
            $sourceColumns = array_keys($this->_getWriteAdapter()->describeTable($this->getIdxTable()));
            $targetColumns = array_keys($this->_getWriteAdapter()->describeTable($this->getNewTable()));
            $select = $this->_getIndexAdapter()->select()->from($this->getIdxTable(), $sourceColumns);
            $this->insertFromSelect($select, $this->getNewTable(), $targetColumns, false);

            //rotate the tables
            $this->_getWriteAdapter()->renameTablesBatch(
                array(
                    array('oldName' => $this->getMainTable(), 'newName' => $this->getOldTable()),
                    array('oldName' => $this->getNewTable(), 'newName' => $this->getMainTable())
                )
            );

            //drop table to reclaim table space
            $this->_getIndexAdapter()->dropTable($this->getOldTable());
        } catch (Exception $e) {
            $this->_getWriteAdapter()->dropTable($this->getNewTable());
            $this->_getWriteAdapter()->dropTable($this->getOldTable());
            throw $e;
        }
        return $this;
    }

    /**
     * Refresh stock index for specific product ids
     *
     * @param array $productIds
     * @return Mage_CatalogInventory_Model_Resource_Indexer_Stock
     * @todo refactor to ge rid of sql query
     */
    public function reindexProducts($productIds)
    {
        $adapter = $this->_getWriteAdapter();
        if (!is_array($productIds)) {
            $productIds = array($productIds);
        }
        $parentIds = $this->getRelationsByChild($productIds);
        if ($parentIds) {
            $processIds = array_map('intval',array_merge($parentIds, $productIds));
        } else {
            $processIds = array_map('intval', $productIds);
        }

        // retrieve product types by processIds
        $select = $adapter->select()
            ->from($this->getTable('catalog/product'), array('entity_id', 'type_id'))
            ->where('entity_id IN(?)', $processIds);
        $pairs  = $adapter->fetchPairs($select);

        $byType = array();
        foreach ($pairs as $productId => $typeId) {
            $byType[$typeId][$productId] = $productId;
        }

        $adapter->beginTransaction();
        try {
            $indexers = $this->_getTypeIndexers();
            foreach ($indexers as $indexer) {
                if (isset($byType[$indexer->getTypeId()])) {
                    $indexer->reindexEntity($byType[$indexer->getTypeId()]);
                }
            }
        } catch (Exception $e) {
            $adapter->rollback();
            throw $e;
        }
        $adapter->commit();

        return $this;
    }
}