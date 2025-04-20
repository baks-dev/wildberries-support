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

namespace BaksDev\Wildberries\Support\Messenger\ReplyToReview;

use BaksDev\Support\Answer\Service\AutoMessagesReply;
use BaksDev\Support\Entity\Support;
use BaksDev\Support\Repository\SupportCurrentEvent\CurrentSupportEventRepository;
use BaksDev\Support\Type\Status\SupportStatus;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusClose;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusOpen;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Support\UseCase\Admin\New\SupportHandler;
use BaksDev\Wildberries\Support\Type\WbReviewProfileType;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]

final readonly class AutoReplyWbReviewDispatcher
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private LoggerInterface $logger,
        private SupportHandler $supportHandler,
        private CurrentSupportEventRepository $currentSupportEvent,
    ) {}

    public function __invoke(AutoReplyWbReviewMessage $message): void
    {
        $supportDTO = new SupportDTO();

        $supportEvent = $this->currentSupportEvent
            ->forSupport($message->getId())
            ->find();

        if(false === $supportEvent)
        {
            $this->logger->critical(
                'wildberries-support: Ошибка получения события по идентификатору :'.$message->getId(),
                [self::class.':'.__LINE__],
            );

            return;
        }

        // гидрируем DTO активным событием
        $supportEvent->getDto($supportDTO);

        // обрабатываем только на открытый тикет
        if(false === ($supportDTO->getStatus()->getSupportStatus() instanceof SupportStatusOpen))
        {
            return;
        }

        $supportInvariableDTO = $supportDTO->getInvariable();

        if(is_null($supportInvariableDTO))
        {
            return;
        }

        /**
         * Пропускаем если тикет не является Wildberries Support Review «Отзыв»
         */
        $supportProfileType = $supportInvariableDTO->getType();

        if(false === $supportProfileType->equals(WbReviewProfileType::TYPE))
        {
            return;
        }

        $reviewRating = $message->getRating();

        /**
         * Текст сообщения в зависимости от рейтинга
         * по умолчанию текс с высоким рейтингом, 5 «HIGH»
         */

        $AutoMessagesReply = new AutoMessagesReply();
        $answerMessage = $AutoMessagesReply->high();

        if($reviewRating === 4 || $reviewRating === 3)
        {
            $answerMessage = $AutoMessagesReply->avg();
        }

        if($reviewRating < 3)
        {
            $answerMessage = $AutoMessagesReply->low();
        }

        /**
         * Если известно имя клиента - подставляем для приветствия
         * @var SupportMessageDTO $currentMessage
         */
        $currentMessage = $supportDTO->getMessages()->current();
        $clientName = $currentMessage->getName();

        if(!empty($clientName) && $clientName !== 'Покупатель')
        {
            $answerMessage = sprintf('Здравствуйте, %s! ', $clientName).$answerMessage;
        }

        /** Отправляем сообщение клиенту */

        $supportMessageDTO = new SupportMessageDTO()
            ->setName('admin (WB Seller)')
            ->setMessage($answerMessage)
            ->setDate(new DateTimeImmutable('now'))
            ->setOutMessage();

        $supportDTO
            ->setStatus(new SupportStatus(SupportStatusClose::class)) // закрываем чат
            ->addMessage($supportMessageDTO) // добавляем сформированное сообщение
        ;

        // сохраняем ответ
        $Support = $this->supportHandler->handle($supportDTO);

        if(false === ($Support instanceof Support))
        {
            $this->logger->critical(
                'wildberries-support: Ошибка при отправке автоматического ответа на отзыв',
                [$Support, self::class.':'.__LINE__]
            );
        }
    }
}