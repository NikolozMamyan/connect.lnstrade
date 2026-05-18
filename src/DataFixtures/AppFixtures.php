<?php

namespace App\DataFixtures;

use App\Entity\Commercial;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $commercials = [
            ['hubspotId' => '78020060', 'firstName' => 'Quentin', 'lastName' => 'Strasser', 'email' => 'quentin.strasser@lnstrade.fr'],
            ['hubspotId' => '65156164', 'firstName' => 'Douglas', 'lastName' => 'Woods', 'email' => 'douglas.woods@lnstrade.fr'],
            ['hubspotId' => '65155850', 'firstName' => 'Cyril', 'lastName' => 'Motz', 'email' => 'cyril.motz@lnstrade.fr'],
            ['hubspotId' => '65524033', 'firstName' => 'Savinien', 'lastName' => 'Saint Paul', 'email' => 'savinien.saint-paul@lnstrade.fr'],
            ['hubspotId' => '65157022', 'firstName' => 'Corentin', 'lastName' => 'BURY', 'email' => 'corentin.bury@lnstrade.fr'],
            ['hubspotId' => '77839925', 'firstName' => 'Enzo', 'lastName' => 'Houdé', 'email' => 'enzo.houde@lnstrade.fr'],
            ['hubspotId' => '78818212', 'firstName' => 'Jerome', 'lastName' => 'Degreve', 'email' => 'jerome.degreve@lnstrade.fr'],
            ['hubspotId' => '29391503', 'firstName' => 'Vincent', 'lastName' => 'TOUATI', 'email' => 'vincent.touati@lnstrade.fr'],
            ['hubspotId' => '65669769', 'firstName' => 'Anthony', 'lastName' => 'Chaoui', 'email' => 'anthony.chaoui@lnstrade.fr'],
        ];

        foreach ($commercials as $data) {
            $commercial = (new Commercial())
                ->setFirstName($data['firstName'])
                ->setLastName($data['lastName'])
                ->setEmail($data['email'])
                ->setHubspotId($data['hubspotId'])
                ->setIsActive(true);

            $manager->persist($commercial);
        }

        $manager->flush();
    }
}
