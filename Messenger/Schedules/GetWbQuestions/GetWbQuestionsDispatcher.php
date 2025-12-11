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

namespace BaksDev\Wildberries\Support\Messenger\Schedules\GetWbQuestions;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Entity\Support;
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
use BaksDev\Wildberries\Repository\AllWbTokensByProfile\AllWbTokensByProfileInterface;
use BaksDev\Wildberries\Support\Api\Question\CheckViewed\PatchWbCheckQuestionViewedRequest;
use BaksDev\Wildberries\Support\Api\Question\QuestionsList\GetWbQuestionsListRequest;
use BaksDev\Wildberries\Support\Schedule\WbNewQuestion\FindProfileForCreateWbQuestionSchedule;
use BaksDev\Wildberries\Support\Type\WbQuestionProfileType;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;


/**
 * Получает новые вопросы WB
 */
#[AsMessageHandler(priority: 0)]
final class GetWbQuestionsDispatcher
{
    private bool $isAddMessage = false;

    public function __construct(
        #[Target('wildberriesSupportLogger')] private readonly LoggerInterface $logger,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly GetWbQuestionsListRequest $GetWbQuestionsListRequest,
        private readonly PatchWbCheckQuestionViewedRequest $patchWbCheckQuestionViewedRequest,
        private readonly CurrentSupportEventByTicketInterface $CurrentSupportEventByTicketRepository,
        private readonly AllWbTokensByProfileInterface $AllWbTokensByProfileRepository,
        private readonly FindExistExternalMessageByIdInterface $FindExistExternalMessageByIdRepository,
        private readonly SupportHandler $supportHandler,
        private readonly TranslatorInterface $translator,
    ) {}

    public function __invoke(GetWbQuestionsMessage $message): void
    {
        /**
         * Ограничиваем лимит сообщений по дате, если вызван диспетчер не из консольной комманды
         *
         * @see UpdateWbQuestionCommand
         */
        if(false === $message->getAddAll())
        {
            $DateTimeFrom = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('GMT'))

                // периодичность scheduler
                ->sub(DateInterval::createFromDateString(FindProfileForCreateWbQuestionSchedule::INTERVAL))

                // 1 минута запас на runtime
                ->sub(DateInterval::createFromDateString('1 minute'))
                ->getTimestamp();

            $this->GetWbQuestionsListRequest->from($DateTimeFrom);
        }

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
             * Получаем новые вопросы
             *
             * @see GetWbQuestionsListRequest
             */
            $questions = $this->GetWbQuestionsListRequest
                ->forTokenIdentifier($WbTokenUid)
                ->findAll();

            if(false === $questions || false === $questions->valid())
            {
                return;
            }

            foreach($questions as $WbQuestionMessageDTO)
            {
                $Deduplicator = $this->deduplicator
                    ->namespace('wildberries-support')
                    ->deduplication([$WbQuestionMessageDTO->getId(), self::class]);

                if($Deduplicator->isExecuted())
                {
                    return;
                }

                if(empty($WbQuestionMessageDTO->getData()))
                {
                    continue;
                }


                /** Если такое сообщение уже есть в БД, то пропускаем */
                $messageExist = $this->FindExistExternalMessageByIdRepository
                    ->external($WbQuestionMessageDTO->getId())
                    ->exist();

                if($messageExist)
                {
                    $Deduplicator->save();
                    continue;
                }

                $ticket = $WbQuestionMessageDTO->getId();


                /**
                 * SupportEvent
                 */
                $SupportDTO = new SupportDTO();

                /** Присваиваем токен для последующего ответа */
                $SupportDTO->getToken()->setValue($WbTokenUid);

                $SupportDTO
                    ->setPriority(new SupportPriority(SupportPriorityLow::class))
                    ->setStatus(new SupportStatus(SupportStatusOpen::class));


                /** SupportInvariable */
                $supportInvariableDTO = new SupportInvariableDTO()
                    ->setProfile($message->getProfile())
                    ->setType(new TypeProfileUid(WbQuestionProfileType::TYPE))
                    ->setTicket($ticket);

                // текущее событие тикета по идентификатору тикета из Wb
                $support = $this->CurrentSupportEventByTicketRepository
                    ->forTicket($ticket)
                    ->find();

                /** Пересохраняю событие с новыми данными */
                false === ($support instanceof SupportEvent) ?: $support->getDto($SupportDTO);

                /** Устанавливаем заголовок чата - выполнится только один раз при сохранении чата */
                if(false === $support)
                {
                    $supportInvariableDTO->setTitle($WbQuestionMessageDTO->getTitle());
                }

                $SupportDTO->setInvariable($supportInvariableDTO);

                // подготовка DTO для нового сообщения
                $supportMessageDTO = new SupportMessageDTO()
                    ->setMessage($WbQuestionMessageDTO->getData())
                    ->setDate($WbQuestionMessageDTO->getCreated())
                    ->setExternal($WbQuestionMessageDTO->getId()) // идентификатор сообщения в WB
                    ->setName($this->translator->trans('user', domain: 'support.admin', locale: $this->translator->getLocale()))
                    ->setInMessage();

                $SupportDTO
                    ->setStatus(new SupportStatus(SupportStatusOpen::class))
                    ->addMessage($supportMessageDTO);

                //$this->isAddMessage ?: $this->isAddMessage = true;

                $this->patchWbCheckQuestionViewedRequest
                    ->forTokenIdentifier($WbTokenUid)
                    ->id($ticket)
                    ->send();

                /** Сохраняем, если имеются новые сообщения в массиве */

                $handle = $this->supportHandler->handle($SupportDTO);

                if(false === $handle instanceof Support)
                {
                    $this->logger->critical(
                        sprintf('wildberries-support: Ошибка %s при создании/обновлении чата поддержки', $handle),
                        [
                            self::class.':'.__LINE__,
                            $message->getProfile(),
                            $SupportDTO->getInvariable()?->getTicket(),
                        ],
                    );
                }


                $Deduplicator->save();
            }
        }
    }
}