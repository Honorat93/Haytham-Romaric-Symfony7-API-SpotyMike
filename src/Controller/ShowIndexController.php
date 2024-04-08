<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class ShowIndexController extends AbstractController
{
    #[Route("/", name: "homepage", methods: ["GET"])]
    public function index(): Response
    {
        return $this->render('index.html.twig');
    }

}
