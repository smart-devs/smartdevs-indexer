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

class Smartdevs_Indexer_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * generate a uuid needed for foreign keys
     *
     * @return string
     */
    public function getUUID()
    {
        return sprintf(
            '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        );

    }
}
