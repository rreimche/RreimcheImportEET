<?php

namespace RreimcheImportEET;

use Shopware\Components\Api\Exception\NotFoundException;
use \Shopware\Components\Plugin;
use \Shopware\Components\Api\Manager;


class RreimcheImportEET extends Plugin{

    //private static $categoryAPI;
    //private static $supplierAPI;
    private static $logPath;
    private static $pricelistPath;
    private static $processedPricePath;
    private static $numberCurrent;
    private static $numberErrors;
    private static $numberCreates;
    private static $numberUpdates;
    private static $numberSourceArticles;
    private static $numberFilteredArticles;
    private static $numberSkipped;
    private static $numberSkippedRandom;
    private static $devMode;
    private static $mehrPreisSatz;


    /*public function install()
    {
        $this->subscribeEvent('Enlight_Controller_Front_StartDispatch', 'onRegisterSubscriber');
        $this->subscribeEvent('Shopware_Console_Add_Command', 'onRegisterSubscriber');

        return true;
    }*/

   /* public function onRegisterSubscriber(Enlight_Event_EventArgs $args)
    {
        Shopware()->Events()->addSubscriber(new \Shopware\RrimcheImportEET\MySubscriber());
    }*/

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
//            'Enlight_Controller_Dispatcher_ControllerPath_Api_Banner' => 'onGetHertellerApiController',
//            'Enlight_Controller_Front_StartDispatch' => 'onEnlightControllerFrontStartDispatch'
            'Shopware_CronJob_importEET' => 'onImportEET',
//            'Shopware_CronJob_preprocessEET' => 'onPreprocessEET'
        ];
    }


    /*
     * Imports Data from EET-Pricelist into Database
     */

    public function onImportEET(\Enlight_Event_EventArgs $args)
    {//TODO löschen alles was nicht in CSV aber in datenbank
        //TODO OEM, UPC/EAN
        //Script started working at
        $time_start = microtime(true);

        RreimcheImportEET::$logPath = $this->container->get('config')->getByNamespace('RreimcheImportEET', 'logPath');
        RreimcheImportEET::$pricelistPath = $this->container->get('config')->getByNamespace('RreimcheImportEET', 'pricelistPath');
        RreimcheImportEET::$numberCurrent = 0;
        RreimcheImportEET::$numberErrors = 0;
        RreimcheImportEET::$numberCreates = 0;
        RreimcheImportEET::$numberUpdates = 0;
        RreimcheImportEET::$numberSkipped = 0;
        RreimcheImportEET::$numberSkippedRandom = 0;
        RreimcheImportEET::$devMode = 0;



        if (($logHandle = fopen(RreimcheImportEET::$logPath, 'a')) === False) die("can't open log file");

        fwrite($logHandle, date('Y-m-d H:i:s') . " Process started\n");

        // if we open the CSV, import, else we have an error
        if (($pricelistHandle = fopen(RreimcheImportEET::$pricelistPath, 'r')) !== False) {

            $articleResource = Manager::getResource('article');


            // some initialisations for later use
            // !!! if you change anything here, change also where assingments to $articleData are made !!!
            $columnNamesToUse = array(
                'Item No.',
                'Description',
                'Description 2',
                'Description 3',
                'Item Group Web Tree',
                'Brand Name',
                'Home stock',
                'Gross Weight',
                'Customers Price',
                'Item Picture Link (Web)'); // Columns we need from CSV
            $columnIndexes = array(); // indexes of the needed columns from CSV

            // fill $columnIndexes
            $columnNames = fgetcsv($pricelistHandle, 0, $delimiter = ';');
            foreach ($columnNamesToUse as $name) {
                if (($index = array_search($name, $columnNames)) !== False) {
                    $columnIndexes[$name] = $index;
                } else {
                    //workaround for an error if a required column was not found
                    fwrite($logHandle, date('Y-m-d H:i:s') . ': Column ' . $name . " not found when parsing CSV\n");

                    //script finished working at
                    $time_end = microtime(true);

                    //dividing with 60 will give the execution time in minutes other wise seconds
                    $execTimeSec = $time_end - $time_start;
                    $execTimeMin = $execTimeSec / 60;

                    //Write status to log and eventually send notification to admin
                    $message = date('Y-m-d H:i:s') . ': Process ended in ' . $execTimeMin . ' minutes with ' . RreimcheImportEET::$numberErrors . " errors, "
                        . RreimcheImportEET::$numberUpdates . " updates and "
                        . RreimcheImportEET::$numberCreates . " creates.\n";
                    fwrite($logHandle, $message);
                    fwrite(STDOUT, $message);

                    die('Column ' . $name . ' not found when parsing CSV');
                }
            }


            //for every line of CSV take the fields and
            while ($line = fgetcsv($pricelistHandle, 0, $delimiter = ';')) {

                if( RreimcheImportEET::$devMode === 1) {
                    fwrite($logHandle, "\n" . date('Y-m-d H:i:s') . ": Started processing article "
                        . $line[$columnIndexes['Item No.']] . ", the " . RreimcheImportEET::$numberCurrent . "th article in source price list.\n");

                    RreimcheImportEET::$numberCurrent++;
                }

                //skip most part of articles to speed up import, for testing purposes
                if ( rand(0, 1) < 0.985 && RreimcheImportEET::$devMode === 1 ){
                    RreimcheImportEET::$numberSkippedRandom++;

                    if( RreimcheImportEET::$devMode === 1) {
                        fwrite($logHandle, date('Y-m-d H:i:s') . ": Randomly skipped article.\n");
                    }

                    continue;
                }

                //do not process articles that cost less 5€ – requirement of client TODO more meaningful variable name
                if ( floatval( $line[$columnIndexes['Customers Price']] ) < 5.0 ) {
                    RreimcheImportEET::$numberSkipped++;

                    if( RreimcheImportEET::$devMode === 1) {
                        fwrite($logHandle, date('Y-m-d H:i:s') . ": Skipped a too cheap article.\n");
                    }

                    continue;
                }


                //foreach ($line as $field) { //TODO why don't I use $field? It seems, I don't need it at all.
                // parse the line (take Fields1).
                // But first, gather data from CSV together
                $articleData = array(
                    'name' => $line[$columnIndexes['Description']],
                    'tax' => array(
                        'name' => 'MwSt',
                        'tax' => 19
                    ),
                    'active' => true,
                    'description' => $line[$columnIndexes['Description 2']]
                        . " " . $line[$columnIndexes['Description 3']],
                    'categories' => array(
                        array('path' => RreimcheImportEET::provideCategory($line[$columnIndexes['Item Group Web Tree']]))
                    ),
                    'supplier' => $line[$columnIndexes['Brand Name']],
                    'mainDetail' => array(
                        'number' => RreimcheImportEET::importArticleNumber($line[$columnIndexes['Item No.']]),
                        'active' => true,
                        'inStock' => $line[$columnIndexes['Home Stock']],
                        'prices' => array(
                            array(
                                'customerGroupKey' => 'EK',
                                'from' => 1,
                                'to' => '',
                                'price' => $line[$columnIndexes['Customers Price']], //Todo $mehrPreisSatz
                            )
                        ),
                        'attribute' => array(
                            'attr1' => $line[$columnIndexes['Item No.']],
                        ),
                    ),
                );

                if ($line[$columnIndexes['Item Picture Link (Web)']] != "") {
                    $articleData['images'] = array(
                        array('link' => RreimcheImportEET::provideImageURL($line[$columnIndexes['Item Picture Link (Web)']]))
                    );
                }
                //}


                //Create and persist an article
                $noSuchArticle = FALSE;
                try {


                    $article = $articleResource->getOneByNumber($articleData['mainDetail']['number']);

                    // an article has only one image in the price list, so if it is already uploaded, don't do that again!
                    // time&space loss
                    if (is_array($article['images']) && (sizeof($article['images']) > 0)) {
                        unset($articleData['images']);
                    }
                    $articleResource->update($article['id'], $articleData);

                    //$articleResource->updateByNumber($articleData['mainDetail']['number'], $articleData);

                    RreimcheImportEET::$numberUpdates++;

                    if( RreimcheImportEET::$devMode === 1){
                        fwrite($logHandle,  date('Y-m-d H:i:s') . ": Updated article " . $articleData['mainDetail']['attribute']['attr1']
                            . " (shopware number ". $articleData['mainDetail']['number'] . ").\n");
                    }

                } catch (NotFoundException $e) {
                    $noSuchArticle = TRUE;

                } catch (\Exception $e) {
                    RreimcheImportEET::$numberErrors++;
                    $message = date('Y-m-d H:i:s') . ': The article ' . $articleData['mainDetail']['attribute']['attr1'] . ' could not be saved ('
                        . $e->getMessage() . "). The exception comes from " . $e->getFile() . " on line " . $e->getLine() . ".\n";
                    fwrite($logHandle, $message);
                }

                try {
                    if ($noSuchArticle === TRUE) {
                        $articleResource->create($articleData);

                        RreimcheImportEET::$numberCreates++;

                        if( RreimcheImportEET::$devMode === 1){
                            fwrite($logHandle,  date('Y-m-d H:i:s') . ": Added article " . $articleData['mainDetail']['attribute']['attr1']
                                . " (shopware number ". $articleData['mainDetail']['number'] . ").\n");
                        }
                    }
                } catch (\Exception $e) {
                    RreimcheImportEET::$numberErrors++;
                    $message = date('Y-m-d H:i:s') . ': The article ' . $articleData['mainDetail']['attribute']['attr1'] . ' could not be saved ('
                        . get_class($e) . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . ": " . $e->getMessage() . ")\n";
                    fwrite($logHandle, $message);
                }

            }
        } else {
            fwrite($logHandle, date('Y-m-d H:i:s') . ": Pricelist could not be opened\n");
            die('Pricelist could not be opened');
        }


        //script finished working at
        $time_end = microtime(true);

        //dividing with 60 will give the execution time in minutes other wise seconds
        $execTimeSec = $time_end - $time_start;
        $execTimeMin = $execTimeSec / 60;

        //Write status to log and eventually send notification to admin
        $message = date('Y-m-d H:i:s') . ': Process ended in ' . $execTimeMin . ' minutes with ' . RreimcheImportEET::$numberErrors . " errors, "
            . RreimcheImportEET::$numberUpdates . " updates and "
            . RreimcheImportEET::$numberCreates . " creates, "
            . RreimcheImportEET::$numberSkipped . " skipped";
        if( RreimcheImportEET::$devMode === 1 ) {
            $message = $message . ", " . RreimcheImportEET::$numberSkippedRandom . " skipped by randomizing";
        }
        $message .= " " . RreimcheImportEET::$numberCurrent . " articles were processed in total.\n";

        fwrite($logHandle, $message);
        //fwrite(STDOUT, $message);

        fclose($pricelistHandle);
        fclose($logHandle);

    }

    /*
     * Imports Data from EET-Pricelist into Database
     */

    public function onImportEET_prepare(\Enlight_Event_EventArgs $args){
        //TODO löschen alles was nicht in CSV aber in datenbank
        //Script started working at
        $time_start = microtime(true);


        RreimcheImportEET::$logPath = '/home/vagrant/www/shopware/log/eetPrepare.txt'; //$this->getPath() + '../../,,/var/log/importEET.txt';
        RreimcheImportEET::$processedPricePath = '/home/vagrant/www/shopware/files/processed_eet.csv';
        RreimcheImportEET::$pricelistPath = "/home/vagrant/www/shopware/files/full.csv";
        RreimcheImportEET::$numberErrors = 0;
        RreimcheImportEET::$numberSourceArticles = 0;
        RreimcheImportEET::$numberFilteredArticles = 0;


        if (($logHandle = fopen(RreimcheImportEET::$logPath, 'a')) === False) die("can't open log file");

        fwrite($logHandle, date('Y-m-d H:i:s') . " Process started\n");

        // if we open the CSV, import, else we have an error
        if ( (($pricelistHandle = fopen(RreimcheImportEET::$pricelistPath, 'r')) !== False)
            && (($processedPriceHandle = fopen(RreimcheImportEET::$processedPricePath, 'w')) !== False) ) {


            $columnNamesToUse = array(
                'Customers Price',
            );
            $columnIndexes = array(); // indexes of the needed columns from CSV

            // fill $columnIndexes
            $columnNames = fgetcsv($pricelistHandle, 0, $delimiter = ';');
            foreach ($columnNamesToUse as $name) {
                if (($index = array_search($name, $columnNames)) !== False) {
                    $columnIndexes[$name] = $index;
                } else {
                    //workaround for an error if a required column was not found
                    fwrite($logHandle, date('Y-m-d H:i:s') . ': Column ' . $name . " not found when parsing CSV\n");

                    //script finished working at
                    $time_end = microtime(true);

                    //dividing with 60 will give the execution time in minutes other wise seconds
                    $execTimeSec = $time_end - $time_start;
                    $execTimeMin = $execTimeSec / 60;

                    //Write status to log and eventually send notification to admin
                    $message = date('Y-m-d H:i:s') . ': Process ended in ' . $execTimeMin . ' minutes with ' . RreimcheImportEET::$numberErrors . " errors, "
                        . RreimcheImportEET::$numberUpdates . " updates and "
                        . RreimcheImportEET::$numberCreates . " creates.\n";
                    fwrite($logHandle, $message);
                    fwrite(STDOUT, $message);

                    die('Column ' . $name . ' not found when parsing CSV');
                }
            }

            //put header

            $csvHeader = array_map(function($f){
                return '"' . $f . '"';
            },$columnNames);

            $csvLine = implode(";", $csvHeader) . "\n";

            fwrite($processedPriceHandle, $csvLine);


            //for every line of CSV take the fields and save them to another file but don't save the ones where Price is less 5
            while ($line = fgetcsv($pricelistHandle, 0, $delimiter = ';')) {
                RreimcheImportEET::$numberSourceArticles++;

                $line[$columnIndexes['Customers Price']] = str_replace(",", ".", $line[$columnIndexes['Customers Price']]);

                if( floatval($line[$columnIndexes['Customers Price']]) > 5 ){

                    $line = array_map(function($f){
                        return '"' . $f . '"';
                    },$line);

                    $csvLine = implode(";", $line) . "\n";

                    fwrite($processedPriceHandle, $csvLine);

                    RreimcheImportEET::$numberFilteredArticles++;
                }
            }
        } else {
            fwrite($logHandle, date('Y-m-d H:i:s') . ": Pricelist could not be opened\n");
            die('Pricelist could not be opened');
        }


        //script finished working at
        $time_end = microtime(true);

        //dividing with 60 will give the execution time in minutes other wise seconds
        $execTimeSec = $time_end - $time_start;
        $execTimeMin = round($execTimeSec / 60, 2);

        //Write status to log and eventually send notification to admin
        $message = date('Y-m-d H:i:s') . ': Process ended in ' . $execTimeMin . ' minutes, '
            . RreimcheImportEET::$numberSourceArticles . ' articles in source file, '
            . RreimcheImportEET::$numberFilteredArticles . " articles after filtering.\n";

        fwrite($logHandle, $message);
        fwrite(STDOUT, $message);

        fclose($pricelistHandle);
        fclose($processedPriceHandle);
        fclose($logHandle);
    }

    // Helper function that finds a category by its path or creates the category as well as part of the path that is yet
    // not present.
    private static function provideCategory($eetItemWebTree){
        if( !$eetItemWebTree) return 'Deutsch';

        $eetItemWebTree = str_replace(" > ", "|", $eetItemWebTree);
        $eetItemWebTree = 'Deutsch|' . $eetItemWebTree;

        return $eetItemWebTree;

        //return RreimcheImportEET::$categoryAPI->findCategoryByPath($eetItemWebTree, true);
    }

    // Helper function that provides correct URL for an image
    private static function provideImageURL($url){
        if( substr($url, 0, 4) === "https" ){
            return substr_replace($url, "http", 0, 4);
        }

        return $url;
    }

    // Helper function that translates an article number from EET into an article number that is
    // acceptable by Shopware to use as mainNumber and orderNumber (or simply as Articles - Details - number)
    private static function importArticleNumber($number){
        //must be at least 4 symbols, so add some symbols
        /*if( ($strlen = strlen($number)) < 4 ){
            // just add some '*' to the end to for number to be at least 4 symbols.
            // as long as all source $numbers are unique, all of the results hier are also unique,
            // because there is an inherited uniqueness in the source $numbers.
            // Compute how much symbols must be added and add them to the end of the $number.
            for( $i= 4 - $strlen; i < 4 ; $i++){
                $number .= '*';
            }
        }*/

        //must start with a letter (Buchstabe)
        /*if ( !ctype_alpha(substr($number, 0, 1)) ){
            // add a letter in front of the $number
            // so we have uniquiness of numbers as long as the source numbers are unique
            // (see description of a loop above for details)
            $number = 'FS_' . $number; //TODO change numbers scheme in shopware to start with FS
        }*/

        // must be 4 characters at least: we add 3 to the worst case of only 1
        // must start with a letter: first of the added characters is a letter
        $number = 'FS_' . $number; //TODO change numbers scheme in shopware to start with FS

        //erhält nur erlaubte zeichen (0-9, A-Z, *, -, _) RegEx: /[\w*-]/
        $chars = str_split($number);
        for($i=0; $i < count($chars); $i++){
            //check for pattern, replace if not valid
            if ( !preg_match('/[\w-*]/', $chars[$i]) ){
                $chars[$i] = ord($chars[$i]);
            }
        }
        return implode($chars);

        //return $number;
    }

}
