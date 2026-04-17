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
use BaksDev\Products\Product\Entity\Event\ProductEvent;
use BaksDev\Products\Product\Repository\ProductByArticle\ProductEventByArticleInterface;
use BaksDev\Products\Review\Entity\Review\ProductReview;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Category\NewProductReviewCategoryDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Name\NewProductReviewNameDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\NewProductReviewDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\NewProductReviewHandler;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Product\NewProductReviewProductDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Profile\NewProductReviewProfileDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Rating\NewProductReviewRatingDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Status\NewProductReviewStatusDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Text\NewProductReviewTextDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\Type\NewProductReviewTypeDTO;
use BaksDev\Products\Review\UseCase\CurrentUser\Review\NewEdit\User\NewProductReviewUserDTO;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Repository\RandomUserProfileByProjectUser\RandomUserProfileByProjectUserInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Wildberries\Support\Type\WbReviewProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Создание product review на основе WB Reviews
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler]
final readonly class WildberriesReviewDispatcher
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private LoggerInterface $logger,
        private NewProductReviewHandler $NewProductReviewHandler,
        private ProductEventByArticleInterface $eventByArticleRepository,
        private DeduplicatorInterface $deduplicator,
        private UserByUserProfileInterface $userByUserProfile,
        private RandomUserProfileByProjectUserInterface $profileByProjectUser,
        #[Autowire(env: 'PROJECT_USER')] private string|null $projectUser = null,
    ) {}

    public function __invoke(WildberriesReviewMessage $message): void
    {

        $isExecuted = $this
            ->deduplicator
            ->expiresAfter('1 minute')
            ->deduplication([$message->getExternal(), self::class]);

        if($isExecuted->isExecuted())
        {
            return;
        }

        $isExecuted->save();


        /** Подготовка данных для отзыва */

        $productsReviewDTO = new NewProductReviewDTO();

        /** Product */

        /* Получить ProductEvent по внешнему идентификатору товара, (равный product article) */
        /** @var ProductEvent|false $productEvent */
        $productEvent = $this->eventByArticleRepository->findProductEventByArticle($message->getArticle());

        if(false === $productEvent)
        {
            $this->logger->warning(
                sprintf('wildberries-support: Не найден товар по article %s', $message->getArticle()),
                [self::class.':'.__LINE__],
            );

            return;
        }

        $productUid = $productEvent->getMain();
        $productsReviewDTO->setProduct(new NewProductReviewProductDTO()->setValue($productUid));

        /** Category */

        $rootCategory = $productEvent->getRootCategory();
        $productsReviewDTO->setCategory(new NewProductReviewCategoryDTO()->setValue($rootCategory));


        /** Status */
        $productsReviewDTO->setStatus(new NewProductReviewStatusDTO()); // ПО умолчанию - 'submitted' (На проверке)


        /** User */

        /* Получим user по profile */
        $user = $this->userByUserProfile->forProfile($message->getProfile())->find();

        if(false === $user)
        {
            $this->logger->warning(
                sprintf('wildberries-support: Не найден пользователь по профилю %s', $message->getProfile()),
                [self::class.':'.__LINE__],
            );

            return;
        }

        $userDTO = new NewProductReviewUserDTO()->setValue($user->getId());

        $productsReviewDTO->setUser($userDTO);


        /** Name */

        $nameDTO = new NewProductReviewNameDTO()->setValue($message->getAuthor());
        $productsReviewDTO->setName($nameDTO);


        /** Rating */

        $ratingDTO = new NewProductReviewRatingDTO()->setValue($message->getRating());
        $productsReviewDTO->setRating($ratingDTO);


        /* Добавить текст сообщения */

        $NewProductReviewTextDTO = new NewProductReviewTextDTO();
        $NewProductReviewTextDTO->setValue($message->getText());

        $productsReviewDTO->setText($NewProductReviewTextDTO);


        /** Добавить профиль */

        $profile = $message->getProfile();

        if(false === empty($this->projectUser))
        {
            /* Получить рандомный профиль (Магазин) по PROJECT_USER из .env */
            $randomProfile = $this->profileByProjectUser
                ->forUser($this->projectUser)
                ->find();

            if(true === ($randomProfile instanceof UserProfileUid))
            {
                $profile = $randomProfile;
            }
        }

        $NewProductReviewProfileDTO = new NewProductReviewProfileDTO()->setValue($profile);

        $productsReviewDTO->setProfile($NewProductReviewProfileDTO);


        /** Добавить ProductReviewType */

        $NewProductReviewTypeDTO = new NewProductReviewTypeDTO();

        /* Идентификатор токена */
        $NewProductReviewTypeDTO->setToken($message->getToken());

        /* Тип маркетплейса */
        $TypeProfileUid = new TypeProfileUid(WbReviewProfileType::TYPE);
        $NewProductReviewTypeDTO->setType($TypeProfileUid);

        /** Внешний идентификатор сообщения/отзыва */
        $NewProductReviewTypeDTO->setExternal($message->getExternal());

        $productsReviewDTO->setType($NewProductReviewTypeDTO);


        /* Создать отзыв */
        $productReview = $this->NewProductReviewHandler->handle($productsReviewDTO);

        if(false === ($productReview instanceof ProductReview))
        {
            $this->logger->critical(
                sprintf('wildberries-support: Ошибка %s при создании отзыва', $productReview),
                [self::class.':'.__LINE__],
            );
        }

    }
}