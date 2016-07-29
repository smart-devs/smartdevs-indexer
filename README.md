#Magento Indexer Patches
This patch provides more performance to the EAV Attribute indexer and also Stock indexer.

## what is it doing
The Indexer are rewritten to use optimized SQL Queries instead of array mappings etc. To avoid a blowing InnoDB transaction log and also wasted table space we implemented table rotation.

## known issues and their fix
#### Error
```sql
SQLSTATE[HY000]: General error: 1114 The table 'catalog_product_index_eav_tmpx' is full, query was: INSERT INTO `catalog_product_index_eav_tmpx` (`entity_id`,`attribute_id`,`store_id`,`value`) VALUES (?, ?, ?, ?),  ...
```
#### Fix
Edit the my.cnf and increase the values for max_heap_table_size and tmp_table_size. I mostly set around 256M as value.

## Contributing

1. Fork it ( https://github.com/smart-devs/smartdevs-indexer/fork )
2. Create your feature branch (`git checkout -b my-new-feature`)
3. Commit your changes (`git commit -am 'Add some feature'`)
4. Push to the branch (`git push origin my-new-feature`)
5. Create a new Pull Request