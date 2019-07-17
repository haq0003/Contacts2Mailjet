<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Leads2MailjetCommand extends ContainerAwareCommand
{
    protected $output;

    protected function configure()
    {
        $this
            ->setName('Leads2Mailjet')
            ->setDescription('...')
            ->addArgument('argument', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option', null, InputOption::VALUE_NONE, 'Option description');

        // php bin/console Leads2Mailjet

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $argument = $input->getArgument('argument');

        if ($input->getOption('option')) {
            // ...
        }

        /**
         * Connexion to table where we have all leads
         */

        $config = new \Doctrine\DBAL\Configuration();
        $connectionParams = array(
            'dbname'    => "XXXXXX",
            'user'      => "XXXXXX",
            'password'  => "XXXXXX",
            'host'      => "XXXXXX",
            'port'      =>  XXXXXX,
            'driver'    => "pdo_mysql",
            'charset'   => "UTF8"
        );

        $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

        // Fetch leads from database each hour
        $sitesWeb = $conn->query("SELECT `siteWeb` FROM leads WHERE `createat` > (NOW() - INTERVAL 1 HOUR) GROUP BY `siteWeb`")->fetchAll();

        /*
         * /!\ IMPORTANT /!\
         * do not forget to add record spf include:spf.mailjet.com and DKIM on your DNS
         * https://app.mailjet.com/account/domain
         */


        $mj = new \Mailjet\Client('API_KEY', 'API_PASS');
        $response = $mj->get(\Mailjet\Resources::$Contactslist, ['filters' => ['limit' => 100]]);

        if (!$response->success()) {
            throw new \Exception('Connection to Mailjet fail ! : Status ' . $response->getStatus());
        }
        $contacts_list = $response->getData();

        /*
         * Check if mailjetList Exist else we create it
         */

        foreach ($sitesWeb as $_site) {
            $urlSite = ((($u = parse_url($_site['siteWeb'], PHP_URL_HOST)) ? $u : $_site['siteWeb']));

            try {
                if ($urlSite && !$this->existList($urlSite, $contacts_list)) {
                    // on cree la list
                    $this->mywWriteln("<info>Create List with name : $urlSite </info>");
                    $response = $mj->post(\Mailjet\Resources::$Contactslist, ['body' => ['Name' => $urlSite]]);
                    $this->mywWriteln("<comment>{$response->getReasonPhrase()} : {$response->getStatus()} </comment>");
                }

            } catch (\Exception $e) {
                $this->mywWriteln("<error> error : {$e->getMessage()} - {$e->getLine()}</error>");
            }
        }

        /*
         * Fetch leads
         */

        $leads = $conn->query("SELECT * FROM leads WHERE `createat` > (NOW() - INTERVAL 1 HOUR) AND (inMailjet != \"yes\" OR inMailjet IS NULL ) ")->fetchAll();

        foreach ($leads as $_lead) {
            $urlSite = ((($u = parse_url($_lead['siteWeb'], PHP_URL_HOST)) ? $u : $_lead['siteWeb']));
            if (!$urlSite) continue;

            $response = $mj->get(\Mailjet\Resources::$Contactslist, ['filters' => ['NameLike' => $urlSite]]);
            $this->mywWriteln("<comment>{$response->getReasonPhrase()} : {$response->getStatus()} </comment>");

            $listInfo4user = $response->getData();
            if (!isset($listInfo4user[0]) || !is_integer($listInfo4user[0]['ID'])) {
                $mError = "Error : With does not found list for this user [{$_lead['id']},{$_lead['email']}]";
                $this->mywWriteln("<error>$mError </error>");
                $conn->exec("UPDATE `leads` SET `inMailjet` = '$mError' WHERE `leads`.`id` = {$_lead['id']}");
            }

            // Add missing Propertie
            unset($_lead['inMailjet']);

            // Search leads
            $contactMailjet = null;

            $response = $mj->get(\Mailjet\Resources::$Contact, ['method' => 'VIEW', 'ID' => $_lead['email']]);
            if ($response->getCount() == 1) {
                $this->mywWriteln("<info>User {$_lead['email']} already exist </info>");
                $contactMailjet = $response->getData();
            } else {
                $response = $mj->post(\Mailjet\Resources::$Contact, ['body' => ['Email' => $_lead['email']]]);
                $this->mywWriteln("<info>User {$_lead['email']} has been added</info>");
                $contactMailjet = $response->getData();
            }

            if (!$contactMailjet) {
                $this->mywWriteln("<error>User not found</error>");
            }

            // Fetch all Properties
            $response = $mj->get(\Mailjet\Resources::$Contactmetadata, ['filters' => ['limit' => 100]]);

            if (!$response->getCount()) {
                $this->mywWriteln("<error>Any Propertie find ! {$response->getReasonPhrase()} : {$response->getStatus()} </error>");
            }

            foreach ($_lead as $property => $u_value) {
                if (!$this->existProperty($property, $response->getData())) {
                    if (in_array($property, array('email', 'id'))) {
                        continue;
                    }
                    // update attributs
                    // str int float bool datetime
                    $datatype = 'str';
                    if (in_array($property, array('createat'))) {
                        $datatype = 'datetime';
                    }

                    $response = $mj->post(\Mailjet\Resources::$Contactmetadata, ['body' =>
                        [
                            'Datatype' => $datatype,
                            'Name' => $property,
                            'NameSpace' => 'static'
                        ]
                    ]);
                    $this->mywWriteln("<comment>Add Propertie $property, {$response->getReasonPhrase()} : {$response->getStatus()} </comment>");
                }
            }

            // We add user in mailjet
            $tmpLeadI = $_lead;

            unset($tmpLeadI['email']);
            unset($tmpLeadI['id']);
            foreach ($tmpLeadI as $_i => $_j) {
                if (in_array($_i, array('createat'))) continue;
                if (in_array($_i, array('Lastname', 'Firstname'))) {
                    $tmpLeadI[$_i] = trim(ucfirst($_j));
                } else {
                    $tmpLeadI[$_i] = trim(strtolower($_j));
                }

            }

            $response = $mj->post(\Mailjet\Resources::$ContactslistManagecontact, ['id' => $listInfo4user[0]['ID'], 'body' => [
                'Email' => $contactMailjet[0]['Email'],
                'Action' => 'addnoforce', // addnoforce addforce
                'Properties' => $tmpLeadI
            ]]);


            $this->mywWriteln("<comment>{$response->getReasonPhrase()} : {$response->getStatus()} </comment>");
            if ($response->getStatus() == 201) {
                $conn->exec("UPDATE `leads` SET `inMailjet` = 'yes' WHERE `leads`.`id` = {$_lead['id']}");
            } else {
                $conn->exec("UPDATE `leads` SET `inMailjet` = 'no' WHERE `leads`.`id` = {$_lead['id']}");
            }

        }

        // delete leads from buffer table
        $conn->exec("DELETE FROM `leads` WHERE `createat` < (NOW() - INTERVAL 1 MONTH ) AND `inMailjet` = 'yes' ORDER BY `leads`.`id` DESC ");

        $this->mywWriteln('End.');
    }

    protected function existProperty($property, $properties_list)
    {
        $res = false;

        if ($properties_list) {

            foreach ($properties_list as $_property) {
                if (isset($_property['Name']) && strtolower($_property['Name']) == strtolower($property)) return true;
            }
        }

        return $res;
    }


    protected function existList($urlSite, $contacts_list): bool
    {

        $res = false;

        if ($contacts_list) {
            foreach ($contacts_list as $_list) {
                if ($_list['Name'] == $urlSite) return true;
            }
        }

        return $res;
    }

    protected function mywWriteln($m)
    {

        $this->output->writeln($m);
    }

}
