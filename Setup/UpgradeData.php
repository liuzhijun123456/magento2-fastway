<?php
namespace DCOnline\Fastway\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Payment\Model\Method\AbstractMethod;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        // provinces of south africa
        $data = [
            ['ZA', 'Eastern Cape', 'Eastern Cape'],
            ['ZA', 'Free State', 'Free State'],
            ['ZA', 'KwaZulu-Natal', 'KwaZulu-Natal'],
            ['ZA', 'Gauteng', 'Gauteng'],
            ['ZA', 'Limpopo', 'Limpopo'],
            ['ZA', 'Mpumalanga', 'Mpumalanga'],
            ['ZA', 'Northern Cape', 'Northern Cape'],
            ['ZA', 'North West', 'North West'],
            ['ZA', 'Western Cape', 'Western Cape']
        ];
        foreach ($data as $row) {
            $bind = ['country_id' => $row[0], 'code' => $row[1], 'default_name' => $row[2]];
            $setup->getConnection()->insert($setup->getTable('directory_country_region'), $bind);
            $regionId = $setup->getConnection()->lastInsertId($setup->getTable('directory_country_region'));

            $bind = ['locale' => 'en_US', 'region_id' => $regionId, 'name' => $row[2]];
            $setup->getConnection()->insert($setup->getTable('directory_country_region_name'), $bind);
        }
        // citys of province ?

        $setup->endSetup();
    }
}
