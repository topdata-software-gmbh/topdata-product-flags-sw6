<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldInstaller
{
    public const SET_NAME = 'topdata_product_flags';
    public const FIELD_TOPSELLER = 'topdata_is_topseller';
    public const FIELD_FRESH = 'topdata_is_fresh_product';

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository
    ) {
    }

    public function install(Context $context): void
    {
        if ($this->setExists($context)) {
            return;
        }

        $this->customFieldSetRepository->create([
            [
                'name' => self::SET_NAME,
                'config' => [
                    'label' => [
                        'en-GB' => 'Topdata Product Flags',
                        'de-DE' => 'Topdata Produkt-Markierungen',
                    ],
                ],
                'relations' => [
                    ['entityName' => 'product'],
                ],
                'customFields' => [
                    [
                        'name' => self::FIELD_TOPSELLER,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Is Topseller',
                                'de-DE' => 'Ist Topseller',
                            ],
                            'customFieldType' => 'checkbox',
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'name' => self::FIELD_FRESH,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Is Fresh Product',
                                'de-DE' => 'Ist neues Produkt',
                            ],
                            'customFieldType' => 'checkbox',
                            'customFieldPosition' => 2,
                        ],
                    ],
                ],
            ],
        ], $context);
    }

    public function uninstall(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));

        $result = $this->customFieldSetRepository->searchIds($criteria, $context);

        if ($result->getTotal() === 0) {
            return;
        }

        $ids = array_map(static fn (string $id): array => ['id' => $id], $result->getIds());
        $this->customFieldSetRepository->delete($ids, $context);
    }

    private function setExists(Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}