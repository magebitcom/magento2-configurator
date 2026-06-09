<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Component;

use Magebit\Configurator\Api\ComponentMode;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Api\VersionManagementInterface;
use Magebit\Configurator\Component\CustomerAttributes;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magento\Customer\Model\ResourceModel\Attribute as AttributeResource;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\AttributeRepository;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as AttrOptionCollectionFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Swatches\Helper\Data as SwatchHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerAttributesTest extends TestCase
{
    private EavSetup&MockObject $eavSetup;
    private AttributeRepository&MockObject $attributeRepository;
    private CustomerSetupFactory&MockObject $customerSetupFactory;
    private AttributeResource&MockObject $attributeResource;
    private LoggerInterface&MockObject $log;
    private AttrOptionCollectionFactory&MockObject $attrOptionCollectionFactory;
    private EavConfig&MockObject $eavConfig;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private SwatchHelper&MockObject $swatchHelper;
    private CustomerAttributes $component;

    protected function setUp(): void
    {
        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->attributeRepository = $this->createMock(AttributeRepository::class);
        $this->customerSetupFactory = $this->getMockBuilder(CustomerSetupFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->attributeResource = $this->createMock(AttributeResource::class);
        $this->log = $this->createMock(LoggerInterface::class);
        $this->attrOptionCollectionFactory = $this->getMockBuilder(AttrOptionCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->swatchHelper = $this->createMock(SwatchHelper::class);

        // Real gate over a version store that always reports "not newer".
        $versionManagement = $this->createMock(VersionManagementInterface::class);
        $versionManagement->method('isNewVersion')->willReturn(false);
        $gate = new ReconciliationGate($versionManagement, $this->log);

        $this->component = new CustomerAttributes(
            $this->eavSetup,
            $this->attributeRepository,
            $this->customerSetupFactory,
            $this->attributeResource,
            $this->log,
            $this->attrOptionCollectionFactory,
            $this->eavConfig,
            $gate,
            $this->searchCriteriaBuilder,
            $this->swatchHelper
        );
    }

    public function testCreatesAttributeWhenItDoesNotExist(): void
    {
        $this->givenAttributeDoesNotExist();

        // New attribute => addAttribute persisted once.
        $this->eavSetup->expects($this->once())
            ->method('addAttribute')
            ->with('customer', 'my_attr', $this->anything());

        // For a brand-new attribute, addAdditionalValues wires the customer forms.
        $this->givenCustomerSetupAttribute();
        $this->attributeResource->expects($this->once())->method('save');

        $result = $this->execute([
            'my_attr' => ['label' => 'My Attr', 'input' => 'text'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testCreateModeProtectsExistingAttribute(): void
    {
        // Exists and differs, no version bump => create mode skips it.
        $this->givenAttributeExists(['frontend_label' => 'Old Label', 'frontend_input' => 'text']);

        $this->eavSetup->expects($this->never())->method('addAttribute');
        $this->customerSetupFactory->expects($this->never())->method('create');
        $this->attributeResource->expects($this->never())->method('save');

        $result = $this->execute([
            'my_attr' => ['label' => 'New Label', 'input' => 'text'],
        ]);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    public function testMaintainModeUpdatesExistingDifferingAttribute(): void
    {
        // Exists with a differing label => maintain mode updates it.
        $this->givenAttributeExists(['frontend_label' => 'Old Label', 'frontend_input' => 'text']);

        $this->eavSetup->expects($this->once())
            ->method('addAttribute')
            ->with('customer', 'my_attr', $this->anything());

        // addAdditionalValues short-circuits for an existing attribute: no form wiring.
        $this->customerSetupFactory->expects($this->never())->method('create');
        $this->attributeResource->expects($this->never())->method('save');

        $result = $this->execute([
            'my_attr' => ['label' => 'New Label', 'input' => 'text'],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
    }

    public function testMaintainModeSkipsUnchangedAttribute(): void
    {
        // Existing attribute already matches the config on every mapped field => skip.
        $this->givenAttributeExists(['frontend_label' => 'My Attr', 'frontend_input' => 'text']);

        $this->eavSetup->expects($this->never())->method('addAttribute');
        $this->attributeResource->expects($this->never())->method('save');

        $result = $this->execute([
            'my_attr' => ['label' => 'My Attr', 'input' => 'text'],
        ], false, null, ComponentMode::Maintain);

        $this->assertSame(1, $result->getSkipped());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->givenAttributeDoesNotExist();

        // Dry run records intent but never touches the EAV setup or the resource.
        $this->eavSetup->expects($this->never())->method('addAttribute');
        $this->customerSetupFactory->expects($this->never())->method('create');
        $this->attributeResource->expects($this->never())->method('save');

        $result = $this->execute([
            'my_attr' => ['label' => 'My Attr', 'input' => 'text'],
        ], true);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getCreated());
    }

    public function testRecordsErrorWhenNodeMissing(): void
    {
        $this->eavSetup->expects($this->never())->method('addAttribute');
        $this->attributeResource->expects($this->never())->method('save');

        $result = $this->execute([], false, ['something_else' => []]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testRecordsErrorWhenNodeIsNotAnArray(): void
    {
        $this->eavSetup->expects($this->never())->method('addAttribute');

        $result = $this->execute([], false, ['customer_attributes' => 'not-an-array']);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrors());
    }

    /**
     * @param array $customerAttributes value of the `customer_attributes` node
     * @param array|null $rawData full source override (bypasses $customerAttributes)
     */
    private function execute(
        array $customerAttributes,
        bool $dryRun = false,
        ?array $rawData = null,
        ComponentMode $mode = ComponentMode::Create
    ): ComponentResult {
        $data = $rawData ?? ['customer_attributes' => $customerAttributes];
        $context = new ComponentContext('test.yaml', $mode, 'test', $dryRun, static fn (): array => $data);

        return $this->component->execute($context);
    }

    private function givenAttributeDoesNotExist(): void
    {
        // EavSetup::getAttribute() returns false when the attribute is absent.
        $this->eavSetup->method('getAttribute')->willReturn(false);
    }

    /**
     * @param array $attributeArray the EAV row EavSetup::getAttribute() returns
     */
    private function givenAttributeExists(array $attributeArray): void
    {
        $attributeArray += ['attribute_id' => 42];
        $this->eavSetup->method('getAttribute')->willReturn($attributeArray);
    }

    /**
     * Wire CustomerSetupFactory->create()->getEavConfig()->getAttribute()->addData()
     * so addAdditionalValues() can reach attributeResource->save() for a new attribute.
     */
    private function givenCustomerSetupAttribute(): void
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('addData')->willReturnSelf();

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $customerSetup = $this->createMock(CustomerSetup::class);
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);

        $this->customerSetupFactory->method('create')->willReturn($customerSetup);
    }
}
