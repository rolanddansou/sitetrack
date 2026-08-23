<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CollectController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/collect/event.js')]
        private string $eventScriptPath
    ) {}

    #[Route('/collect/event.js', name: 'collect_event_script', methods: ['GET'])]
    public function eventScript(Request $request): Response
    {
        $response = new Response(file_get_contents($this->eventScriptPath));
        $response->headers->set('Content-Type', 'application/javascript; charset=utf-8');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->setSharedMaxAge(3600);
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp(filemtime($this->eventScriptPath)));

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
