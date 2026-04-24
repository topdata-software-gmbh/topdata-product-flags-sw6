<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class AdminApiExampleController extends AbstractController
{
    #[Route(
        path: '/api/_action/topdata-product-flags-sw6/example', 
        name: 'api.action.productflagssw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}