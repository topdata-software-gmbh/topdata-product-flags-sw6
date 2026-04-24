---
filename: "_ai/backlog/active/240525_1000__IMPLEMENTATION_PLAN__product_flags_custom_fields.md"
title: "Implement Custom Fields and CLI Commands for Product Flags"
createdAt: 2024-05-25 10:00
updatedAt: 2024-05-25 10:00
status: completed
priority: high
tags: [custom-fields, cli-commands, product, shopware6]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## Problem Statement
The shop needs a way to flag products as "Topseller" (`is_topseller`) and "Fresh Product" (`is_fresh_product`). These flags must be stored as custom fields on the product entity. Furthermore, the updating of these flags needs to be automated via CLI commands that accept specific criteria (like maximum age in days, maximum product limit, etc.) so they can be run periodically (e.g., via cronjob).

## Executive Summary
This implementation plan adds two new boolean custom fields (`topdata_is_topseller`, `topdata_is_fresh_product`) to the product entity by hooking into the plugin lifecycle. It also introduces two new Symfony Console commands (`topdata:product-flags:update-topseller` and `topdata:product-flags:update-fresh`) to automatically compute and assign these flags based on configurable parameters like `--max-products`, `--min-sales`, and `--max-age`. Finally, the documentation will be updated to include command usage instructions and a Twig template snippet demonstrating how to display these flags in a custom theme.

## Project Environment Details
```
- Shopware 6.7.*
- PHP 8.2+
- Custom Fields API (Shopware DAL)
- Symfony Console (Commands)
```

## Phase 1: Custom Fields Installation Setup
We will create a helper service to manage the custom fields during plugin installation/uninstallation and hook it into the main plugin class.

```php
// [NEW FILE] src/Service/CustomFieldInstaller.php
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
        
        $ids = array_map(fn($id) => ['id' => $id], $result->getIds());
        $this->customFieldSetRepository->delete($ids, $context);
    }

    private function setExists(Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));
        
        return $this->customFieldSetRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
```

```php
// [MODIFY] src/TopdataProductFlagsSW6.php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Topdata\TopdataProductFlagsSW6\Service\CustomFieldInstaller;

class TopdataProductFlagsSW6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->getCustomFieldInstaller()->install($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getCustomFieldInstaller()->uninstall($uninstallContext->getContext());
    }

    private function getCustomFieldInstaller(): CustomFieldInstaller
    {
        return new CustomFieldInstaller(
            $this->container->get('custom_field_set.repository')
        );
    }
}
```

## Phase 2: Create CLI Commands
Create the commands to update the custom fields, applying logic to find products.

```php
// [NEW FILE] src/Command/UpdateTopsellerCommand.php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataProductFlagsSW6\Service\CustomFieldInstaller;

#[AsCommand(
    name: 'topdata:product-flags:update-topseller',
    description: 'Updates the is_topseller custom field for products based on sales.'
)]
class UpdateTopsellerCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-products', 'm', InputOption::VALUE_OPTIONAL, 'Maximum number of topseller products', '50')
            ->addOption('min-sales', 's', InputOption::VALUE_OPTIONAL, 'Minimum sales to qualify as topseller', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        
        $maxProducts = (int) $input->getOption('max-products');
        $minSales = (int) $input->getOption('min-sales');

        $io->info(sprintf('Updating topseller flags (Max: %d, Min Sales: %d)', $maxProducts, $minSales));

        // Step 1: Reset existing topsellers
        $this->resetTopsellers($context);

        // Step 2: Set new topsellers
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('sales', [RangeFilter::GTE => $minSales]));
        $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
        $criteria->setLimit($maxProducts);

        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();

        if (empty($productIds)) {
            $io->warning('No products found matching the topseller criteria.');
            return Command::SUCCESS;
        }

        $updates = array_map(function ($id) {
            return [
                'id' => $id,
                'customFields' => [CustomFieldInstaller::FIELD_TOPSELLER => true],
            ];
        }, $productIds);

        $this->productRepository->update($updates, $context);

        $io->success(sprintf('Successfully set %d products as topseller.', count($productIds)));

        return Command::SUCCESS;
    }

    private function resetTopsellers(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_TOPSELLER, true));
        
        $ids = $this->productRepository->searchIds($criteria, $context)->getIds();
        
        if (empty($ids)) {
            return;
        }

        $updates = array_map(function ($id) {
            return [
                'id' => $id,
                'customFields' => [CustomFieldInstaller::FIELD_TOPSELLER => false],
            ];
        }, $ids);

        // Process in chunks to avoid memory issues
        foreach (array_chunk($updates, 100) as $chunk) {
            $this->productRepository->update($chunk, $context);
        }
    }
}
```

```php
// [NEW FILE] src/Command/UpdateFreshProductCommand.php
<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataProductFlagsSW6\Service\CustomFieldInstaller;

#[AsCommand(
    name: 'topdata:product-flags:update-fresh',
    description: 'Updates the is_fresh_product custom field based on creation or release date.'
)]
class UpdateFreshProductCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', 'a', InputOption::VALUE_OPTIONAL, 'Maximum age in days to be considered fresh', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        
        $maxAgeDays = (int) $input->getOption('max-age');
        $dateThreshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $maxAgeDays))->format(\DATE_ATOM);

        $io->info(sprintf('Updating fresh product flags (Max age: %d days, Threshold: %s)', $maxAgeDays, $dateThreshold));

        // Step 1: Reset flags for products older than threshold
        $this->resetOldFreshProducts($context, $dateThreshold);

        // Step 2: Set flag for products newer than threshold
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new RangeFilter('releaseDate', [RangeFilter::GTE => $dateThreshold]),
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('releaseDate', null),
                new RangeFilter('createdAt', [RangeFilter::GTE => $dateThreshold])
            ])
        ]));
        // Only get products not yet flagged to save writes
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_FRESH, null),
            new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_FRESH, false)
        ]));

        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();

        if (!empty($productIds)) {
            $updates = array_map(function ($id) {
                return [
                    'id' => $id,
                    'customFields' => [CustomFieldInstaller::FIELD_FRESH => true],
                ];
            }, $productIds);

            foreach (array_chunk($updates, 100) as $chunk) {
                $this->productRepository->update($chunk, $context);
            }
        }

        $io->success(sprintf('Successfully set %d products as fresh.', count($productIds)));

        return Command::SUCCESS;
    }

    private function resetOldFreshProducts(Context $context, string $dateThreshold): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_FRESH, true));
        
        // Find products older than threshold
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new RangeFilter('releaseDate', [RangeFilter::LT => $dateThreshold]),
                new MultiFilter(MultiFilter::CONNECTION_AND, [
                    new EqualsFilter('releaseDate', null),
                    new RangeFilter('createdAt', [RangeFilter::LT => $dateThreshold])
                ])
            ])
        ]));

        $ids = $this->productRepository->searchIds($criteria, $context)->getIds();
        
        if (empty($ids)) {
            return;
        }

        $updates = array_map(function ($id) {
            return [
                'id' => $id,
                'customFields' => [CustomFieldInstaller::FIELD_FRESH => false],
            ];
        }, $ids);

        foreach (array_chunk($updates, 100) as $chunk) {
            $this->productRepository->update($chunk, $context);
        }
    }
}
```

## Phase 3: Service Registration Updates
Register the new commands in the DI container.

```xml
// [MODIFY] src/Resources/config/services.xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="Topdata\TopdataProductFlagsSW6\Controller\StorefrontExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
            <call method="setTwig">
                <argument type="service" id="twig"/>
            </call>
        </service>

        <service id="Topdata\TopdataProductFlagsSW6\Controller\AdminApiExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- New Commands -->
        <service id="Topdata\TopdataProductFlagsSW6\Command\UpdateTopsellerCommand">
            <argument type="service" id="product.repository"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataProductFlagsSW6\Command\UpdateFreshProductCommand">
            <argument type="service" id="product.repository"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

## Phase 4: Documentation Updates
Update the README to include CLI command usage and the Twig theme example.

```markdown
// [MODIFY] README.md
# Topdata Product Flags SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview

This plugin provides backend automation for managing product flags via custom fields. It introduces custom fields for identifying products as "Topseller" or "Fresh Product" and provides CLI commands to calculate and update these flags automatically based on your product data.

## Custom Fields Added
- `topdata_is_topseller` (Boolean)
- `topdata_is_fresh_product` (Boolean)

## CLI Commands

You can run these commands manually or configure them as periodic cronjobs.

### Update Topsellers
Identifies the best-selling products and sets their `topdata_is_topseller` flag to true, while resetting the flag for products that no longer qualify.

```bash
bin/console topdata:product-flags:update-topseller --max-products 50 --min-sales 10
```
**Options:**
- `--max-products` (default 50): Maximum number of top products to flag.
- `--min-sales` (default 10): The minimum sales a product needs to be considered.

### Update Fresh Products
Evaluates the `releaseDate` (or `createdAt` if `releaseDate` is empty) and sets the `topdata_is_fresh_product` flag to true if the product was released within the defined days.

```bash
bin/console topdata:product-flags:update-fresh --max-age 30
```
**Options:**
- `--max-age` (default 30): The maximum age of the product in days to still be considered "fresh".

## Frontend Integration (Theme Example)

The display logic is completely separated and should be handled by your custom storefront theme. Use the following Twig example in your product box template (e.g., `storefront/component/product/card/badges.html.twig`) to display the flags:

```twig
{% sw_extends '@Storefront/storefront/component/product/card/badges.html.twig' %}

{% block component_product_badges %}
    {{ parent() }}
    
    {# Topdata Topseller Badge #}
    {% if product.translated.customFields.topdata_is_topseller %}
        <div class="badge bg-warning badge-topseller">
            {{ "topseller.badgeLabel"|trans|sw_sanitize }}
        </div>
    {% endif %}

    {# Topdata Fresh Product Badge #}
    {% if product.translated.customFields.topdata_is_fresh_product %}
        <div class="badge bg-success badge-new-product">
            {{ "freshProduct.badgeLabel"|trans|sw_sanitize }}
        </div>
    {% endif %}
{% endblock %}
```

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin
4. The custom fields will be automatically created upon installation.

## Requirements

- Shopware 6.7.*

## License

MIT
```

---

## Phase 5: Implementation Report
Create the implementation report detailing the executed tasks.

```markdown
---
filename: "_ai/backlog/reports/240525_1000__IMPLEMENTATION_REPORT__product_flags_custom_fields.md"
title: "Report: Implement Custom Fields and CLI Commands for Product Flags"
createdAt: 2024-05-25 10:15
updatedAt: 2024-05-25 10:15
planFile: "_ai/backlog/active/240525_1000__IMPLEMENTATION_PLAN__product_flags_custom_fields.md"
project: "TopdataProductFlagsSW6"
status: completed
filesCreated: 3
filesModified: 3
filesDeleted: 0
tags: [custom-fields, cli-commands, product, shopware6]
documentType: IMPLEMENTATION_REPORT
---

### 1. Summary
Successfully implemented the Product Flags custom fields and CLI functionality. The plugin now registers `topdata_is_topseller` and `topdata_is_fresh_product` fields on plugin installation, and provides configurable Symfony console commands to batch-update these fields.

### 2. Files Changed
**Created:**
- `src/Service/CustomFieldInstaller.php`: Handles standard Custom Field creation and cleanup logic for the plugin lifecycle.
- `src/Command/UpdateTopsellerCommand.php`: Command logic for `--max-products` and `--min-sales` calculations.
- `src/Command/UpdateFreshProductCommand.php`: Command logic for checking product age using `--max-age`.

**Modified:**
- `src/TopdataProductFlagsSW6.php`: Added hooks for `install()` and `uninstall()` to trigger `CustomFieldInstaller`.
- `src/Resources/config/services.xml`: Registered the new Command services.
- `README.md`: Updated with comprehensive usage instructions and a frontend theme integration example using Twig.

### 3. Key Changes
- Bound the generation of `topdata_product_flags` custom field set directly into the `Plugin::install()` method utilizing Shopware DAL repositories.
- Integrated automated cleanup logic on plugin removal if `KeepUserData` is false.
- Structured queries in CLI commands carefully to only update products that require changes to save DB writes, applying data chunking (100 items per batch).
- Implemented `MultiFilter` and `RangeFilter` logic to robustly compare dates and handle empty `releaseDate` fields by falling back to `createdAt`.

### 4. Technical Decisions
- **Custom Field Naming**: Explicitly namespaced the field keys as `topdata_is_...` to avoid collision with standard fields or other plugins.
- **Bulk Updates**: Used the batch-update methodology natively supported by the generic `EntityRepository::update()` function for performance when dealing with potentially thousands of product lines.

### 5. Testing Notes
- Run `bin/console plugin:install --activate TopdataProductFlagsSW6` and verify custom fields appear in the administration.
- Run `bin/console topdata:product-flags:update-topseller` and `bin/console topdata:product-flags:update-fresh` then check your DB or Administration to ensure the custom fields toggle properly based on product properties.
- Use `bin/console plugin:uninstall TopdataProductFlagsSW6` to confirm custom fields are removed properly (without `keepUserData`).

### 6. Usage Examples
- Make top 20 sellers topsellers if they have at least 5 sales: `bin/console topdata:product-flags:update-topseller -m 20 -s 5`
- Flag products released in the last 15 days as fresh: `bin/console topdata:product-flags:update-fresh -a 15`

### 7. Documentation Updates
Complete documentation override done on the README mapping out the CLI interface as well as explicit instructions and a code snippet on how to visualize it in the storefront.


