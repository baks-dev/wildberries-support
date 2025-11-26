<?php
/*
 * Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Wildberries\Support\Messenger\Schedules\GetWbReviews;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatch;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Entity\Support;
use BaksDev\Support\Repository\ExistTicket\ExistSupportTicketInterface;
use BaksDev\Support\Repository\FindExistMessage\FindExistExternalMessageByIdInterface;
use BaksDev\Support\Repository\SupportCurrentEventByTicket\CurrentSupportEventByTicketInterface;
use BaksDev\Support\Type\Priority\SupportPriority;
use BaksDev\Support\Type\Priority\SupportPriority\Collection\SupportPriorityLow;
use BaksDev\Support\Type\Status\SupportStatus;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusOpen;
use BaksDev\Support\UseCase\Admin\New\Invariable\SupportInvariableDTO;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Support\UseCase\Admin\New\SupportHandler;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Wildberries\Support\Api\Review\ReviewsList\GetWbReviewsListRequest;
use BaksDev\Wildberries\Support\Api\Review\ReviewsList\WbReviewMessageDTO;
use BaksDev\Wildberries\Support\Messenger\ReplyToReview\AutoReplyWbReviewMessage;
use BaksDev\Wildberries\Support\Schedule\WbNewReview\FindProfileForCreateWbReviewSchedule;
use BaksDev\Wildberries\Support\Type\WbReviewProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получает новые вопросы WB
 */
#[AsMessageHandler(priority: 0)]
final readonly class GetWbReviewsDispatcher
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $deduplicator,
        private GetWbReviewsListRequest $GetWbReviewsListRequest,
        private CurrentSupportEventByTicketInterface $supportByWbChat,
        private ExistSupportTicketInterface $ExistSupportTicket,
        private SupportHandler $supportHandler,
        private MessageDispatch $messageDispatch,
    ) {}

    public function __invoke(GetWbReviewsMessage $message): void
    {
        $UserProfileUid = $message->getProfile();

        /**
         * Получаем новые отзывы
         *
         * @see GetWbReviewsListRequest
         */

        $reviews = $this->GetWbReviewsListRequest
            ->profile($UserProfileUid)
            ->findAll();

        if(false === $reviews || false === $reviews->valid())
        {
            return;
        }

        /** @var WbReviewMessageDTO $review */
        foreach($reviews as $review)
        {
            $Deduplicator = $this->deduplicator
                ->namespace('wildberries-support')
                ->expiresAfter('1 hour')
                ->deduplication([$review->getId(), self::class]);

            if($Deduplicator->isExecuted())
            {
                return;
            }

            if(empty($review->getData()))
            {
                continue;
            }

            $ticket = $review->getId();

            /**
             * Пропускаем, если указанный тикет добавлен
             *
             * @see ExistSupportTicketInterface
             */
            $questionExist = $this->ExistSupportTicket
                ->ticket($ticket)
                ->exist();

            if($questionExist)
            {
                continue;
            }

            /** SupportEvent */
            $SupportDTO = new SupportDTO() // done
            ->setPriority(new SupportPriority(SupportPriorityLow::class))
                ->setStatus(new SupportStatus(SupportStatusOpen::class));

            /** Присваиваем токен для последующего поиска */
            $SupportDTO->getToken()->setValue($message->getProfile());


            /** SupportInvariable */
            $supportInvariableDTO = new SupportInvariableDTO()
                ->setProfile($UserProfileUid)
                ->setType(new TypeProfileUid(WbReviewProfileType::TYPE))
                ->setTicket($ticket);

            // текущее событие тикета по идентификатору тикета из Wb
            $support = $this->supportByWbChat
                ->forTicket($ticket)
                ->find();

            /** Пересохраняем событие с новыми данными */
            !($support instanceof SupportEvent) ?: $support->getDto($SupportDTO);

            /** Устанавливаем заголовок чата - выполнится только один раз при сохранении чата */
            if(false === $support)
            {
                $supportInvariableDTO->setTitle($review->getTitle());
            }


            // подготовка DTO для нового сообщения
            $supportMessageDTO = new SupportMessageDTO()
                ->setMessage($review->getData())
                ->setDate($review->getCreated())
                ->setExternal($review->getId()) // идентификатор сообщения в WB
                ->setName($review->getName())
                ->setInMessage();

            $SupportDTO
                ->setStatus(new SupportStatus(SupportStatusOpen::class))
                ->setInvariable($supportInvariableDTO)
                ->addMessage($supportMessageDTO);


            /** Сохраняем, если имеются новые сообщения в массиве */

            $handle = $this->supportHandler->handle($SupportDTO);

            if(false === $handle instanceof Support)
            {
                $this->logger->critical(
                    sprintf('wildberries-support: Ошибка %s при создании/обновлении отзывов', $handle),
                    [
                        self::class.':'.__LINE__,
                        $UserProfileUid,
                        $SupportDTO->getInvariable()?->getTicket(),
                    ],
                );

                continue;
            }

            $Deduplicator->save();


            // после добавления отзыва в БД - инициирую авто ответ по условию

            /**
             * Условия ответа на отзывы
             *
             * рейтинг равен 5 с текстом:
             * - авто комментарий с благодарностью (сообщение)
             *
             * рейтинг меньше 5 и без текста:
             * - авто комментарий с извинениями (сообщение)
             *
             * рейтинг меньше 5 с текстом:
             * - отвечает контент менеджер
             */

            $reviewRating = $review->getValuation();

            if($reviewRating === 5 || empty($review->getIsText()))
            {
                /** @var Support $handle */
                $this->messageDispatch->dispatch(
                    message: new AutoReplyWbReviewMessage($handle->getId(), $reviewRating),
                    stamps: [new MessageDelay(FindProfileForCreateWbReviewSchedule::INTERVAL)],
                    transport: 'wildberries-support',
                );
            }
        }
    }
}