<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ExceptionController extends AbstractController
{
    public function show404(): Response
    {
        return $this->render('404.html.twig');
    }
}
