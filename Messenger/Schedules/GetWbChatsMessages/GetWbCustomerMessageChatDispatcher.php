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

namespace BaksDev\Wildberries\Support\Messenger\Schedules\GetWbChatsMessages;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Entity\Support;
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
use BaksDev\Wildberries\Support\Api\Chat\ChatsMessages\GetWbChatsMessagesRequest;
use BaksDev\Wildberries\Support\Api\Chat\ChatsMessages\WbChatMessageDTO;
use BaksDev\Wildberries\Support\Schedule\WbNewReview\FindProfileForCreateWbReviewSchedule;
use BaksDev\Wildberries\Support\Type\WbChatProfileType;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получает новые сообщения из чатов с покупателями WB
 */
#[AsMessageHandler]
final class GetWbCustomerMessageChatDispatcher
{
    private bool $isAddMessage = false;

    public function __construct(
        #[Target('wildberriesSupportLogger')] private readonly LoggerInterface $logger,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly GetWbChatsMessagesRequest $chatsMessagesRequest,
        private readonly CurrentSupportEventByTicketInterface $supportByWbChat,
        private readonly SupportHandler $supportHandler,
    ) {}

    public function __invoke(GetWbCustomerMessageChatMessage $message): void
    {
        /**
         * Ограничиваем лимит сообщений по дате, если вызван диспетчер не из консольной комманды
         * @see UpdateWbChatCommand
         */
        if($message->getAddAll() === false)
        {
            $DateTimeFrom = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('GMT'))

                // периодичность scheduler
                ->sub(DateInterval::createFromDateString(FindProfileForCreateWbReviewSchedule::INTERVAL))

                // 1 минута запас на runtime
                ->sub(DateInterval::createFromDateString('1 minute'))

                ->getTimestamp();

            $DateTimeFrom *= 1000; // Приводим к миллисекундам согласно документации WBApi

            $this->chatsMessagesRequest->next($DateTimeFrom);
        }

        $UserProfileUid = $message->getProfile();

        $messagesChat = $this->chatsMessagesRequest
            ->profile($UserProfileUid)
            ->findAll();

        if(false === $messagesChat || false === $messagesChat->valid())
        {
            return;
        }

        /** @var WbChatMessageDTO $chatMessage */
        foreach($messagesChat as $chatMessage)
        {
            $Deduplicator = $this->deduplicator
                ->namespace('wildberries-support')
                ->deduplication([$chatMessage->getId(), self::class]);

            if($Deduplicator->isExecuted())
            {
                return;
            }

            if($chatMessage->getData() === '')
            {
                continue;
            }

            $ticket = $chatMessage->getChatId();

            /** SupportEvent */
            $supportDTO = new SupportDTO();
            $supportDTO->setPriority(new SupportPriority(SupportPriorityLow::class));
            $supportDTO->setStatus(new SupportStatus(SupportStatusOpen::class));

            /** SupportInvariable */
            $supportInvariableDTO = new SupportInvariableDTO();
            $supportInvariableDTO->setProfile($UserProfileUid);
            $supportInvariableDTO->setType(new TypeProfileUid(WbChatProfileType::TYPE));
            $supportInvariableDTO->setTicket($ticket);

            // текущее событие чата по идентификатору чата (тикета) из Wb
            $support = $this->supportByWbChat
                ->forTicket($ticket)
                ->find();

            /** Пересохраняю событие с новыми данными */
            !($support instanceof SupportEvent) ?: $support->getDto($supportDTO);

            /** Устанавливаем заголовок чата - выполнится только один раз при сохранении чата */
            if(false === $support)
            {
                $title = $chatMessage->getText() ? mb_strimwidth($chatMessage->getText(), 0, 255) : "Без темы";
                $supportInvariableDTO->setTitle($title);
            }

            $supportDTO->setInvariable($supportInvariableDTO);

            // подготовка DTO для нового сообщения
            $supportMessageDTO = new SupportMessageDTO();
            $supportMessageDTO->setMessage($chatMessage->getData());
            $supportMessageDTO->setDate($chatMessage->getCreated());
            $supportMessageDTO->setExternal($chatMessage->getId()); // идентификатор сообщения в WB

            // параметры в зависимости от типа юзера сообщения
            if($chatMessage->getUserType() === 'seller')
            {
                $supportMessageDTO->setName('admin (WB Seller)');
                $supportMessageDTO->setOutMessage();
            }

            if($chatMessage->getUserType() === 'client')
            {
                $supportMessageDTO->setName($chatMessage->getUserName());
                $supportMessageDTO->setInMessage();
            }

            // Если не возможно определить тип - присваиваем идентификатор чата в качестве имени
            if($chatMessage->getUserType() !== 'client' && $chatMessage->getUserType() !== 'seller')
            {
                $supportMessageDTO->setName($chatMessage->getUserId());
                $supportMessageDTO->setInMessage();
            }

            // при добавлении нового сообщения открываем чат заново
            $supportDTO->setStatus(new SupportStatus(SupportStatusOpen::class));
            $supportDTO->addMessage($supportMessageDTO);

            $this->isAddMessage ?: $this->isAddMessage = true;

            /** Сохраняем, если имеются новые сообщения в массиве */
            if(true === $this->isAddMessage)
            {
                $handle = $this->supportHandler->handle($supportDTO);

                if(false === $handle instanceof Support)
                {
                    $this->logger->critical(
                        sprintf('wildberries-support: Ошибка %s при создании/обновлении чата поддержки', $handle),
                        [
                            self::class.':'.__LINE__,
                            $UserProfileUid,
                            $supportDTO->getInvariable()?->getTicket(),
                        ],
                    );
                }
            }
        }

        $Deduplicator->save();
    }
}