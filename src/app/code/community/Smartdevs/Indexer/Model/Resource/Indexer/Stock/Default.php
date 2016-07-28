<?php
/**
 * SmartDevs Indexer Performance fix extension
 *
 * NOTICE OF LICENSE
 *
 * Everyone is permitted to copy and distribute verbatim or modified
 * copies of this license document, and changing it is allowed as long
 * as the name is changed.
 *
 * @category   SmartDevs
 * @package    Smartdevs_Indexer
 * @copyright  Copyright (c) 2016 Smart-Devs UG (haftungsbeschränkt) (http://www.smart-devs.rocks)
 * @license    http://www.wtfpl.net/  DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 * @author     Daniel Niedergesäß <dn@smart-devs.rocks>
 */
class Smartdevs_Indexer_Model_Resource_Indexer_Stock_Default
    extends Mage_CatalogInventory_Model_Resource_Indexer_Stock_Default
    implements Mage_CatalogInventory_Model_Resource_Indexer_Stock_Interface
{
    /**
     * Get the select object for get stock status by product ids
     *
     * @param int|array $entityIds
     * @param bool $usePrimaryTable use primary or temporary index table
     * @return Varien_Db_Select
     */
    protected function _getStockStatusSelect($entityIds = null, $usePrimaryTable = false)
    {
        $adapter = $this->_getWriteAdapter();
        $qtyExpr = $adapter->getCheckSql('cisi.qty > 0', 'cisi.qty', 0);
        $select  = $adapter->select()
            ->from(array('e' => $this->getTable('catalog/product')), array('product_id' => 'entity_id'));
        $this->_addWebsiteJoinToSelect($select, true);
        $this->_addProductWebsiteJoinToSelect($select, 'cw.website_id', 'e.entity_id');
        $select->columns('cw.website_id')
            ->join(
                array('cis' => $this->getTable('cataloginventory/stock')),
                '',
                array('stock_id'))
            ->joinLeft(
                array('cisi' => $this->getTable('cataloginventory/stock_item')),
                'cisi.stock_id = cis.stock_id AND cisi.product_id = e.entity_id',
                array())
            ->columns(array('qty' => $qtyExpr))
            ->where('e.type_id = ?', $this->getTypeId());

        // add limitation of status
        $psExpr = $this->_addAttributeToSelect($select, 'status', 'e.entity_id', 'cs.store_id');
        $psCondition = $adapter->quoteInto($psExpr . '=?', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);

        if ($this->_isManageStock()) {
            $statusExpr = $adapter->getCheckSql('cisi.use_config_manage_stock = 0 AND cisi.manage_stock = 0',
                1, 'cisi.is_in_stock');
        } else {
            $statusExpr = $adapter->getCheckSql('cisi.use_config_manage_stock = 0 AND cisi.manage_stock = 1',
                'cisi.is_in_stock', 1);
        }

        $optExpr = $adapter->getCheckSql($psCondition, 1, 0);
        $stockStatusExpr = $adapter->getLeastSql(array($optExpr, $statusExpr));

        $select->columns(array('stock_status' => $stockStatusExpr));

        if (!is_null($entityIds)) {
            $select->where('e.entity_id IN(?)', array_map('intval', $entityIds));
        }
        $select->order(new Zend_Db_Expr('NULL'));
        return $select;
    }

    /**
     * Update Stock status index by product ids
     *
     * @param array|int $entityIds
     * @return Mage_CatalogInventory_Model_Resource_Indexer_Stock_Default
     */
    protected function _updateIndex($entityIds)
    {
        $adapter = $this->_getWriteAdapter();
        $select  = $this->_getStockStatusSelect($entityIds, true);
        $adapter->query($select
            ->insertFromSelect($this->getMainTable(), array(
                'product_id',
                'website_id',
                'stock_id',
                'qty',
                'stock_status',
            )));
        return $this;
    }
}
