<?php

namespace App\DataFixtures;

use App\Entity\ConnectorObject;
use App\Entity\HubSpotField;
use App\Entity\SageField;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ConnectorCatalogFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        /*
        |----------------------------------
        | CONNECTOR OBJECTS
        |----------------------------------
        */

        $objects = [
            ['system' => 'sage', 'code' => 'article', 'label' => 'Article'],
            ['system' => 'sage', 'code' => 'client', 'label' => 'Client'],
            ['system' => 'sage', 'code' => 'contact', 'label' => 'Contact'],

            ['system' => 'hubspot', 'code' => 'product', 'label' => 'Product'],
            ['system' => 'hubspot', 'code' => 'company', 'label' => 'Company'],
            ['system' => 'hubspot', 'code' => 'contact', 'label' => 'Contact'],
        ];

        $connectorObjects = [];

        foreach ($objects as $data) {
            $object = new ConnectorObject();
            $object->setSystemName($data['system']);
            $object->setCode($data['code']);
            $object->setLabel($data['label']);

            $manager->persist($object);

            $connectorObjects[$data['system'].'_'.$data['code']] = $object;
        }

        /*
        |----------------------------------
        | SAGE FIELDS
        |----------------------------------
        */

        $sageFields = [
            ['object' => 'sage_article', 'code' => 'AR_Ref', 'label' => 'Référence article'],
            ['object' => 'sage_article', 'code' => 'AR_Design', 'label' => 'Désignation'],
            ['object' => 'sage_article', 'code' => 'AR_CodeBarre', 'label' => 'Code Barre'],
            ['object' => 'sage_article', 'code' => 'AR_PoidsNet', 'label' => 'Poids Net'],

            ['object' => 'sage_contact', 'code' => 'CT_Prenom', 'label' => 'Prénom'],
            ['object' => 'sage_contact', 'code' => 'CT_Nom', 'label' => 'Nom'],
            ['object' => 'sage_contact', 'code' => 'CT_Email', 'label' => 'Email'],

            ['object' => 'sage_client', 'code' => 'CT_Num', 'label' => 'Code Client'],
            ['object' => 'sage_client', 'code' => 'CT_Intitule', 'label' => 'Intitulé'],
        ];

        foreach ($sageFields as $data) {

            $field = new SageField();
            $field->setConnectorObject($connectorObjects[$data['object']]);
            $field->setCode($data['code']);
            $field->setLabel($data['label']);

            $manager->persist($field);
        }

        $manager->flush();
    }
}