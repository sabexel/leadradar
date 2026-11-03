<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Classified type of a crawled page.
 */
enum PageType: string
{
    case Home = 'home';
    case Contact = 'contact';
    case About = 'about';
    case Team = 'team';
    case Careers = 'careers';
    case Services = 'services';
    case Other = 'other';
}
