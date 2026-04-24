<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6\Command;

use DateTimeImmutable;
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
    name: 'productflags:update-fresh',
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
        $dateThreshold = (new DateTimeImmutable())->modify(sprintf('-%d days', $maxAgeDays))->format(DATE_ATOM);

        $io->info(sprintf('Updating fresh product flags (Max age: %d days, Threshold: %s)', $maxAgeDays, $dateThreshold));

        $this->resetOldFreshProducts($context, $dateThreshold);

        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new RangeFilter('releaseDate', [RangeFilter::GTE => $dateThreshold]),
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('releaseDate', null),
                new RangeFilter('createdAt', [RangeFilter::GTE => $dateThreshold]),
            ]),
        ]));

        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_FRESH, null),
            new EqualsFilter('customFields.' . CustomFieldInstaller::FIELD_FRESH, false),
        ]));

        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();

        if (!empty($productIds)) {
            $updates = array_map(static function (string $id): array {
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
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new RangeFilter('releaseDate', [RangeFilter::LT => $dateThreshold]),
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('releaseDate', null),
                new RangeFilter('createdAt', [RangeFilter::LT => $dateThreshold]),
            ]),
        ]));

        $ids = $this->productRepository->searchIds($criteria, $context)->getIds();

        if (empty($ids)) {
            return;
        }

        $updates = array_map(static function (string $id): array {
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