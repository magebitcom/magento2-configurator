<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\ExportableComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Export\ExportContext;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;
use Magento\Review\Model\Rating;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\ResourceModel\Rating as RatingResource;
use Magento\Review\Model\ResourceModel\Rating\Option as RatingOptionResource;
use Magento\Review\Model\Rating\Entity;
use Magento\Review\Model\Rating\EntityFactory;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Review\Model\Rating\Option;
use Magento\Review\Model\Rating\OptionFactory;

/**
 * @SuppressWarnings("CouplingBetweenObjects")
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ReviewRating implements ComponentInterface, ExportableComponentInterface
{
    const MAX_NUM_RATINGS = 5;

    private const ALIAS = 'review_rating';
    private const DESCRIPTION = 'Component to create review ratings';

    private ?int $entityId = null;

    public function __construct(
        private readonly RatingFactory $ratingFactory,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly OptionFactory $optionFactory,
        private readonly EntityFactory $entityFactory,
        private readonly RatingResource $ratingResource,
        private readonly RatingOptionResource $optionResource,
        private readonly LoggerInterface $log,
        private readonly ReconciliationGate $gate
    ) {
    }

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (!isset($data['review_rating']) || !is_array($data['review_rating'])) {
            $result->addError('No "review_rating" node found in the source data.');
            return $result;
        }

        $reviewRatings = $this->getReviewRatings($data);
        $dryRun = $context->isDryRun();

        foreach ($reviewRatings as $code => $reviewRating) {
            try {
                /** @var Rating $ratingModel */
                $ratingModel = $this->getReviewRating((string) $code);
                $existed = (bool) $ratingModel->getId();

                $version = $reviewRating['version'] ?? null;
                $request = new ReconciliationRequest(
                    self::ALIAS,
                    (string) $code,
                    $context->getMode(),
                    $existed,
                    $version ? (int) $version : null
                );

                if ($this->gate->decide($request)->isSkip()) {
                    $this->log->logComment(sprintf('Review rating "%s" exists, skipped (create mode)', $code));
                    $result->recordSkipped();
                    continue;
                }

                $ratingModel = $this->updateOrCreateRating($ratingModel, (string) $code, $reviewRating);

                if ($dryRun) {
                    $this->log->logInfo(sprintf('[dry-run] Would update review rating "%s"', $code));
                    $this->gate->commitVersion($request, $dryRun);
                    $existed ? $result->recordUpdated() : $result->recordCreated();
                    continue;
                }

                $this->ratingResource->save($ratingModel);
                $this->setOptions($ratingModel, $dryRun);
                $this->log->logInfo((string) __('Updated review rating "%1"', $code));
                $this->gate->commitVersion($request, $dryRun);
                $existed ? $result->recordUpdated() : $result->recordCreated();
            } catch (\Exception $e) {
                $this->log->logError(
                    sprintf(
                        'Failed updating review rating "%s". Error message: %s',
                        $code,
                        $e->getMessage()
                    )
                );
                $result->addError($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Get the review criteria
     *
     * @param array $data
     * @return array
     */
    public function getReviewRatings(array $data): array
    {
        if (isset($data['review_rating'])) {
            return $data['review_rating'];
        }
        return [];
    }

    /**
     * @param string $reviewRatingCode
     * @return Rating
     */
    public function getReviewRating(string $reviewRatingCode): Rating
    {
        /** @var Rating $rating */
        $rating = $this->ratingFactory->create();
        $rating->load($reviewRatingCode, 'rating_code');
        return $rating;
    }

    /**
     * @param Rating $rating
     * @param string $ratingCode
     * @param array $ratingData
     * @return Rating
     */
    public function updateOrCreateRating(Rating $rating, string $ratingCode, array $ratingData): Rating
    {
        $rating->setRatingCode($ratingCode);
        $reviewEntityId = $this->getReviewEntityId();
        $rating->setEntityId($reviewEntityId);
        $isActive = 0;
        if (isset($ratingData['is_active'])) {
            $isActive = $ratingData['is_active'];
        }
        $rating->setData('is_active', $isActive);

        $position = 0;
        if (isset($ratingData['position'])) {
            $position = $ratingData['position'];
        }
        $rating->setData('position', $position);

        $stores = [];
        if (isset($ratingData['stores'])) {
            $stores = $this->getStoresByCodes($ratingData['stores']);
        }
        $rating->setStores($stores);
        return $rating;
    }

    /**
     * Sets the options on the rating
     *
     * @param Rating $rating
     */
    protected function setOptions(Rating $rating, bool $dryRun): void
    {
        $ratingOptions = $rating->getOptions();
        if (count($ratingOptions) === self::MAX_NUM_RATINGS) {
            return;
        }
        $alreadyCreated = [];

        foreach ($ratingOptions as $ratingOption) {
            $alreadyCreated[] = $ratingOption->getCode();
        }
        for ($count = 1; $count <= self::MAX_NUM_RATINGS; $count++) {
            if (in_array($count, $alreadyCreated)) {
                continue;
            }

            if ($dryRun) {
                $this->log->logInfo(
                    sprintf('[dry-run] Would create rating option %d for rating "%s"', $count, $rating->getRatingCode())
                );
                continue;
            }

            /** @var Option $option */
            $option = $this->optionFactory->create();
            $option->setRatingId($rating->getId());
            $option->setCode($count);
            $option->setValue($count);
            $option->setPosition($count);
            $this->optionResource->save($option);
        }
    }

    /**
     * @param array|string $storeCodes
     * @return array
     */
    public function getStoresByCodes($storeCodes): array
    {
        $storesResponse = [];

        if (!is_array($storeCodes)) {
            $storeCodes = [$storeCodes];
        }

        foreach ($storeCodes as $storeCode) {
            $storeModel = $this->storeRepository->get($storeCode);
            $storesResponse[] = $storeModel->getId();
        }

        return $storesResponse;
    }

    /**
     * Get the review entity ID
     *
     * @return int
     */
    private function getReviewEntityId(): int
    {
        if ($this->entityId === null) {
            /** @var Entity $entity */
            $entity = $this->entityFactory->create();
            $this->entityId = (int) $entity->getIdByCode('product');
        }
        return $this->entityId;
    }

    /**
     * Export current review rating criteria into the source format. Refresh mode
     * rewrites only the rating codes already tracked in the source file; full mode
     * dumps every rating bound to the `product` review entity (optionally filtered
     * by a rating-code prefix). Each entry carries its current is_active, position
     * and assigned store codes.
     */
    public function export(ExportContext $context): array
    {
        return $context->isFullExport()
            ? $this->exportAll($context->getFilter())
            : $this->refreshTracked($context->getExistingData());
    }

    /**
     * Rebuild the tracked ratings from their current DB state, preserving any
     * non-value keys (e.g. version). A tracked code that no longer exists in the
     * DB keeps its existing entry untouched.
     *
     * @param array $existing
     * @return array
     */
    private function refreshTracked(array $existing): array
    {
        $tracked = $existing['review_rating'] ?? null;
        if (!is_array($tracked)) {
            return $existing;
        }

        $out = [];
        foreach ($tracked as $code => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $rating = $this->getReviewRating((string) $code);

            if (!$rating->getId()) {
                $out[$code] = $tracked[$code];
                continue;
            }

            $out[$code] = $this->mergeRatingValues($entry, $rating);
        }

        return ['review_rating' => $out];
    }

    /**
     * Export every rating bound to the `product` review entity, keyed by code.
     *
     * @param string|null $filter
     * @return array
     */
    private function exportAll(?string $filter): array
    {
        /** @var \Magento\Review\Model\ResourceModel\Rating\Collection $collection */
        $collection = $this->ratingFactory->create()->getCollection();
        $collection->addEntityFilter($this->getReviewEntityId());

        $out = [];
        foreach ($collection as $rating) {
            $code = (string) $rating->getRatingCode();
            if ($filter !== null && $filter !== '' && !str_starts_with($code, $filter)) {
                continue;
            }

            $out[$code] = $this->mergeRatingValues([], $rating);
        }

        return ['review_rating' => $out];
    }

    /**
     * Overwrite the value keys (is_active, position, stores) of an entry with the
     * rating's current DB state, preserving any other keys already present.
     *
     * @param array $entry
     * @param Rating $rating
     * @return array
     */
    private function mergeRatingValues(array $entry, Rating $rating): array
    {
        $entry['is_active'] = (int) $rating->getData('is_active');
        $entry['position'] = (int) $rating->getData('position');
        $entry['stores'] = $this->getStoreCodesByRating($rating);

        return $entry;
    }

    /**
     * Resolve the store codes a rating is assigned to (in the order returned by
     * the resource model). Store ids that no longer resolve are skipped.
     *
     * @param Rating $rating
     * @return array
     */
    private function getStoreCodesByRating(Rating $rating): array
    {
        $codes = [];
        foreach ($this->ratingResource->getStores((int) $rating->getId()) as $storeId) {
            try {
                $codes[] = $this->storeRepository->getById((int) $storeId)->getCode();
            } catch (\Exception $e) {
                $this->log->logComment(
                    sprintf('Skipping unknown store id "%s" for rating "%s"', $storeId, $rating->getRatingCode())
                );
            }
        }

        return $codes;
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }
}
