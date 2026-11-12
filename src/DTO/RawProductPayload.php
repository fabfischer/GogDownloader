<?php

namespace App\DTO;

/**
 * What GOG returns for one owned product, unchanged: the account's view of it (gameDetails: which DLC and extras the
 * account owns) and the product API with its downloads and expanded DLC.
 *
 * Unlike GameDetail, nothing is filtered or normalized away: versions, file IDs and the parts of multi-part
 * installers, language packs, the DLC each download belongs to and the type of each extra all stay.
 */
final readonly class RawProductPayload
{
    /**
     * @param array<mixed> $owned   embed.gog.com/account/gameDetails/{id}.json
     * @param array<mixed> $product api.gog.com/products/{id}?expand=downloads,expanded_dlcs
     */
    public function __construct(
        public int $productId,
        public array $owned,
        public array $product,
    ) {
    }
}
