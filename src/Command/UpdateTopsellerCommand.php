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
    name: 'productflags:update-topseller',
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

        $this->resetTopsellers($context);

        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('sales', [RangeFilter::GTE => $minSales]));
        $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
        $criteria->setLimit($maxProducts);

        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();

        if (empty($productIds)) {
            $io->warning('No products found matching the topseller criteria.');

            return Command::SUCCESS;
        }

        $updates = array_map(static function (string $id): array {
            return [
                'id' => $id,
                'customFields' => [CustomFieldInstaller::FIELD_TOPSELLER => true],
            ];
        }, $productIds);

        foreach (array_chunk($updates, 100) as $chunk) {
            $this->productRepository->update($chunk, $context);
        }

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

        $updates = array_map(static function (string $id): array {
            return [
                'id' => $id,
                'customFields' => [CustomFieldInstaller::FIELD_TOPSELLER => false],
            ];
        }, $ids);

        foreach (array_chunk($updates, 100) as $chunk) {
            $this->productRepository->update($chunk, $context);
        }
    }
}