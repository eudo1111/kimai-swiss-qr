<?php

namespace KimaiPlugin\SwissQrBundle\EventSubscriber;

use App\Event\InvoicePreRenderEvent;
use KimaiPlugin\SwissQrBundle\Service\SwissQrService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InvoiceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SwissQrService $swissQrService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoicePreRenderEvent::class => 'onInvoicePreRender',
        ];
    }

    public function onInvoicePreRender(InvoicePreRenderEvent $event): void
    {
        $event->getModel()->addModelHydrator($this->swissQrService);
    }
}
