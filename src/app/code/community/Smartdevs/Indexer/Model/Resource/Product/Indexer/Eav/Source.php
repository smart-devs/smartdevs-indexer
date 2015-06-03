<?php

/**
 * Catalog Product Eav Select and Multiply Select Attributes Indexer resource model
 *
 * @category    Smartdevs
 * @package     Smartdevs_Indexer
 * @author      Daniel Niedergesäß <daniel.niedergesaess@gmail.com>
 */
class Smartdevs_Indexer_Model_Resource_Product_Indexer_Eav_Source extends Mage_Catalog_Model_Resource_Product_Indexer_Eav_Source
{
    const OLD_SUFFIX = '_old';
    const NEW_SUFFIX = '_new';

    /**
     * flag temporary is table created
     *
     * @var bool
     */
    private $_tmpTableCreated = false;

    /**
     * Prepare data index for indexable select attributes
     *
     * @param array $entityIds the entity ids limitation
     * @param int $attributeId the attribute id limitation
     * @return Mage_Catalog_Model_Resource_Product_Indexer_Eav_Source
     */
    protected function _prepareSelectIndex($entityIds = null, $attributeId = null)
    {
        $adapter = $this->_getWriteAdapter();
        $idxTable = $this->getIdxTable();
        // prepare select attributes
        if (is_null($attributeId)) {
            $attrIds = $this->_getIndexableAttributes(false);
        } else {
            $attrIds = array($attributeId);
        }

        if (!$attrIds) {
            return $this;
        }

        /**@var $subSelect Varien_Db_Select */
        $subSelect = $adapter->select()
            ->from(
                array('s' => $this->getTable('core/store')),
                array('store_id', 'website_id')
            )
            ->joinLeft(
                array('d' => $this->getValueTable('catalog/product', 'int')),
                '1 = 1 AND d.store_id = 0',
                array('entity_id', 'attribute_id', 'value')
            )
            //added missing attribute filter
            ->where('s.store_id != 0 and d.attribute_id IN (?)', array_map('intval', $attrIds));

        if (!is_null($entityIds)) {
            $subSelect->where('d.entity_id IN(?)', array_map('intval', $entityIds));
        }

        /**@var $select Varien_Db_Select */
        $select = $adapter->select()
            ->from(
                array('pid' => new Zend_Db_Expr(sprintf('(%s)', $subSelect->assemble()))),
                array()
            )
            ->joinLeft(
                array('pis' => $this->getValueTable('catalog/product', 'int')),
                'pis.entity_id = pid.entity_id AND pis.attribute_id = pid.attribute_id AND pis.store_id = pid.store_id',
                array()
            )
            ->columns(
                array(
                    'pid.entity_id',
                    'pid.attribute_id',
                    'pid.store_id',
                    'value' => $adapter->getIfNullSql('pis.value', 'pid.value')
                )
            )
            ->where('pid.attribute_id IN(?)', $attrIds);

        $select->where(Mage::getResourceHelper('catalog')->getIsNullNotNullCondition('pis.value', 'pid.value'));

        /**
         * Add additional external limitation
         */
        Mage::dispatchEvent('prepare_catalog_product_index_select', array(
            'select' => $select,
            'entity_field' => new Zend_Db_Expr('pid.entity_id'),
            'website_field' => new Zend_Db_Expr('pid.website_id'),
            'store_field' => new Zend_Db_Expr('pid.store_id')
        ));
        $query = $select->insertFromSelect($idxTable);
        $adapter->query($query);
        return $this;
    }

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
        return $this->getMainTable() . self::NEW_SUFFIX;
    }

    /**
     * Retrieve old index table name
     *
     * @return string
     */
    private function getOldTable()
    {
        return $this->getMainTable() . self::OLD_SUFFIX;
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
     * Retrieve temporary index table name
     *
     * @param string $table
     * @return string
     */
    public function getIdxTable($table = null)
    {
        //check we already have created a temp table
        if (false === $this->_tmpTableCreated) {
            $table = $this->_getWriteAdapter()->createTableByDdl(
                $this->getTable('catalog/product_eav_indexer_tmp'),
                $this->getTable('catalog/product_eav_indexer_tmp') . 'x'
            );
            $this->_getWriteAdapter()->createTemporaryTable($table);
            $this->_tmpTableCreated = true;
        }
        return $this->getTable('catalog/product_eav_indexer_tmp') . 'x';
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

            //add join for bypassing disabled products and avoid heavy delete query
            $condition = $this->_getIndexAdapter()->quoteInto('=?', Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE);
            $this->_addAttributeToSelect(
                $select,
                'visibility',
                $this->getIdxTable() . '.entity_id',
                $this->getIdxTable() . '.store_id',
                $condition
            );

            //disable index updates
            $this->_getWriteAdapter()->enableTableKeys($this->getNewTable());
            $this->insertFromSelect($select, $this->getNewTable(), $targetColumns, false);

            //sync parent child relation data @see _prepareRelationIndex
            $write = $this->_getWriteAdapter();
            $idxTable = $this->getIdxTable();

            $select = $this->_getWriteAdapter()->select()
                ->from(array('l' => $this->getTable('catalog/product_relation')), 'parent_id')
                ->join(array('cs' => $this->getTable('core/store')), '', array())
                ->join(array('i' => $idxTable), 'l.child_id = i.entity_id AND cs.store_id = i.store_id', array('attribute_id', 'store_id', 'value'))
                ->group(array('l.parent_id', 'i.attribute_id', 'i.store_id', 'i.value'));
            /**
             * Add additional external limitation
             */
            Mage::dispatchEvent('prepare_catalog_product_index_select', array(
                'select' => $select,
                'entity_field' => new Zend_Db_Expr('l.parent_id'),
                'website_field' => new Zend_Db_Expr('cs.website_id'),
                'store_field' => new Zend_Db_Expr('cs.store_id')
            ));
            $select->order(new Zend_Db_Expr('NULL'));

            //add join for bypassing disabled products and avoid heavy delete query
            $condition = $this->_getIndexAdapter()->quoteInto('=?', Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE);
            $this->_addAttributeToSelect(
                $select,
                'visibility',
                'i.entity_id',
                'i.store_id',
                $condition
            );
            $query = $write->insertFromSelect($select, $this->getNewTable(), array(), Varien_Db_Adapter_Interface::INSERT_IGNORE);
            $this->_getWriteAdapter()->query($query);
            //enable index updates
            $this->_getWriteAdapter()->enableTableKeys($this->getNewTable());
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
     * Rebuild all index data
     *
     * we simply remove here _removeNotVisibleEntityFromIndex because we can handle this with a join
     *
     * @return Mage_Catalog_Model_Resource_Product_Indexer_Eav_Abstract
     */
    public function reindexAll()
    {
        $this->useIdxTable(true);
        $this->beginTransaction();
        try {
            $this->_prepareIndex();
            $this->syncData();
            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
        return $this;
    }
}