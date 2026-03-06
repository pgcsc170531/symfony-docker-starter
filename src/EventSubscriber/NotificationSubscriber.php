<?php

namespace App\EventSubscriber;

use App\Event\NotificationEvent;
use App\Service\NotificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NotificationEvent::NAME => 'onNotification',
        ];
    }

    public function onNotification(NotificationEvent $event): void
    {
        // STRICTLY handle SMS for now.
        if ($event->getChannel() === 'SMS') {
            $this->notificationService->sendSms(
                $event->getSchool(),
                $event->getRecipient(),
                $event->getMessage()
            );
        }
    }
}