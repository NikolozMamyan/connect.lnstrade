<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConverterController extends AbstractController
{
    #[Route('/lns/convertiseurs', name: 'lns_converters', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('converter/index.html.twig');
    }
}
