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
     * create a new stock table
     */
    protected function _createNewTable(){
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
            //create new table
            $this->_createNewTable();
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
     */
    public function reindexProducts($productIds)
    {
        $adapter = $this->_getWriteAdapter();
        if (false === is_array($productIds)) {
            $productIds = array($productIds);
        }
        $parentIds = $this->getRelationsByChild($productIds);
        if ($parentIds) {
            $processIds = array_merge($parentIds, $productIds);
        } else {
            $processIds = $productIds;
        }

        // retrieve product types we need to reindex
        $select = $adapter->select()->distinct(true)
            ->from($this->getTable('catalog/product'), array('type_id'))
            ->where('entity_id IN(?)', $processIds);
        $entityTypes  = $adapter->fetchAssoc($select);

        $adapter->beginTransaction();
        try {
            foreach ($this->_getTypeIndexers() as $indexer) {
                //check we need to index the type
                if (true === array_key_exists($indexer->getTypeId(), $entityTypes)){
                    //we need array map here to ensure we don't mix data types
                    $indexer->reindexEntity(array_map('intval', $processIds));
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