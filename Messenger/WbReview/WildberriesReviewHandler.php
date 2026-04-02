<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Wildberries\Support\Messenger\WbReview;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Products\Review\Repository\FindExistByExternal\FindExistByExternalInterface;
use BaksDev\Wildberries\Repository\AllWbTokensByProfile\AllWbTokensByProfileInterface;
use BaksDev\Wildberries\Support\Api\Review\ReviewsList\GetWbReviewsListRequest;
use BaksDev\Wildberries\Support\Api\Review\ReviewsList\WbReviewMessageDTO;
use BaksDev\Wildberries\Support\Messenger\Schedules\GetWbReviews\GetWbReviewsMessage;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Подготовка данных для product reviews на основе WB Reviews
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler]
final readonly class WildberriesReviewHandler
{
    public function __construct(
        private GetWbReviewsListRequest $GetWbReviewsListRequest,
        private AllWbTokensByProfileInterface $AllWbTokensByProfileRepository,
        private DeduplicatorInterface $deduplicator,
        private FindExistByExternalInterface $existByExternal,
        private MessageDispatchInterface $messageDispatch,
    ) {}

    public function __invoke(GetWbReviewsMessage $message): void
    {

        $isExecuted = $this
            ->deduplicator
            ->expiresAfter('1 minute')
            ->deduplication([$message->getProfile(), self::class]);

        if($isExecuted->isExecuted())
        {
            return;
        }

        $isExecuted->save();


        /**
         * Получаем все токены профиля пользователя
         */

        $tokensByProfile = $this->AllWbTokensByProfileRepository
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }


        foreach($tokensByProfile as $WbTokenUid)
        {

            /**
             * Получаем новые отзывы
             *
             * @see GetWbReviewsListRequest
             */
            $reviews = $this->GetWbReviewsListRequest
                ->forTokenIdentifier($WbTokenUid)
                ->findAll();

            /* Если нет отзывов - прервать работу */
            if(false === $reviews || false === $reviews->valid())
            {
                return;
            }


            /* Итерируемся по полученным отзывам */
            /** @var WbReviewMessageDTO $WbReviewMessageDTO */
            foreach($reviews as $WbReviewMessageDTO)
            {

                /* Проверка на существование отзыва по внешнему Id */

                $reviewExists = $this->existByExternal
                    ->external($WbReviewMessageDTO->getId())
                    ->exist();

                if(true === $reviewExists)
                {
                    continue;
                }


                /* Если нет текста */
                if(true === empty($WbReviewMessageDTO->getText()))
                {
                    continue;
                }


                /* Создать сообщение */
                $WildberriesReviewMessage = new WildberriesReviewMessage(
                    article: $WbReviewMessageDTO->getArticle(),
                    rating: $WbReviewMessageDTO->getValuation(),
                    text: $WbReviewMessageDTO->getText(),
                    token: $WbTokenUid->getValue(),
                    external: $WbReviewMessageDTO->getId(),
                    profile: $message->getProfile(),
                    author: $WbReviewMessageDTO->getName()
                );


                $this->messageDispatch->dispatch(
                    message: $WildberriesReviewMessage,
                    stamps: [new MessageDelay(sprintf('%s seconds', 1))],
                    transport: 'wildberries-support-low',
                );

            }

        }

    }
}