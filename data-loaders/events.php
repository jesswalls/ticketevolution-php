<?php

require_once 'bootstrap.php';
error_reporting (E_ALL);
ini_set('max_execution_time', 1200);

// Set some status data for use in querying/updating the `tevoDataLoaderStatus` table
$statusData = array((string)'table' => 'events');

require_once './includes/common.php';

// Create the Ticketevolution_Db_Table object
$table = new Ticketevolution_Db_Table_Events();

// Create an object for the `tevoEventPerformers` table too
$epTable = new Ticketevolution_Db_Table_Eventperformers();

for($currentPage = $options['page']; $currentPage <= $maxPages; $currentPage++) {
    /*******************************************************************************
     * Fetch the JSON to process
     */
    // Set the current page
    $options['page'] = $currentPage;
    
    // Execute the request
    try{
        $results = $tevo->listEvents($options);
    } catch(Exception $e) {
        throw new Ticketevolution_Webservice_Exception($e);
    }
    
    // Set the correct $maxPages
    if($maxPages == $defaultMaxPages) {
        $maxPages = $results->totalPages();
    }

    if($showStats) {
        $curMem = memory_get_usage(true);
        $curMem = new Zend_Measure_Binary(memory_get_usage(true), Zend_Measure_Binary::BYTE);
        echo '<h2>Current memory usage after fetching page ' . $currentPage . ' of ' . $maxPages . ': ' . $curMem->convertTo(Zend_Measure_Binary::MEGABYTE) . '</h2>' . PHP_EOL;
    }
    
    /*******************************************************************************
     * Process the API results either INSERTing or UPDATEing our table(s)
     */
    foreach($results AS $result) {
        $data = array(
            'eventId' => (int)$result->id,
            'eventName' => (string)$result->name,
            'eventDate' => (string)$result->occurs_at->get(Zend_Date::ISO_8601),
            'venueId' => (int)$result->venue->id,
            'categoryId' => (int)$result->category->id,
            'productsCount' => (int)$result->products_count,
            'eventUrl' => (string)$result->url,
            'updated_at' => (string)$result->updated_at->get(Zend_Date::ISO_8601),
            'eventStatus' => (int)1,
            'eventState' => (string)$result->state,
            'lastModifiedDate' => (string)$now->get(Zend_Date::ISO_8601)
        );
        if(isset($result->configuration->id)) {
            $data['configurationId'] = (int)$result->configuration->id;
        }

        if($row = $table->fetchRow($table->select()->where('`eventId` = ?', $data['eventId']))) {
            $row->setFromArray($data);
        } else {
            $row = $table->createRow($data);
        }
        if(!$row->save()) {
            echo '<h2 class="error">Error attempting to save ' . tohtmlentities($data['eventId'] . ': ' . $data['eventName']) . ' to `tevoEvents`</h2>' . PHP_EOL;
        } else {
            echo '<h2>Saved ' . tohtmlentities($data['eventId'] . ': ' . $data['eventName']) . ' to `tevoEvents`</h2>' . PHP_EOL;
        }
        unset($data);
        unset($row);

        // Set a list of performers we can append names to
        $performerList = (string)'';
        
        // Loop through the performers and add them to the `tevoEventPerformers` table
        foreach($result->performances as $performance) {
            $data = array(
                'eventId' => (int)$result->id,
                'performerId' => (int)$performance->performer->id,
                'isPrimary' => (int)$performance->primary,
                'lastModifiedDate' => (string)$now->get(Zend_Date::ISO_8601)
            );

            if($row = $epTable->fetchRow($epTable->select()->where("`eventId` = ?", (int)$result->id)->where("`performerId` = ?", (int)$performance->performer->id))) {
                $row->setFromArray($data);
            } else {
                $row = $epTable->createRow($data);
            }
            $row->save();
            unset($row);
            $performerArray[] = (int)$performance->performer->id;
            if($data['isPrimary']) {
                $performerList .= '<b>' . $performance->performer->id . '</b>, ';
            } else {
                $performerList .= $performance->performer->id . ', ';
            }
            unset($data);
        } // End loop through performers for this event
        echo '<p>Saved ' . substr($performerList, 0, -2) . ' to `tevoEventPerformers` for this event</p>' . PHP_EOL;
        unset($performerList);
        
        // Now delete any `tevoEventPerformers` entries for any performers not in 
        // $performerList. This will remove any performers that were attached
        // to this event but are no longer
        if(isset($performerArray)) {
            $where = $epTable->getAdapter()->quoteInto("`eventId` = ?", $result->id);
            $where .= $epTable->getAdapter()->quoteInto(" AND `performerId` NOT IN (?)", $performerArray);
            $epTable->delete($where);
            unset($performerArray);
        }
    } // End loop through this page of results
    if($showStats) {
        $curMem = new Zend_Measure_Binary(memory_get_usage(true), Zend_Measure_Binary::BYTE);
        echo '<h2>Current memory usage after database work of page ' . $currentPage . ' of ' . $maxPages . ': ' . $curMem->convertTo(Zend_Measure_Binary::MEGABYTE) . '</h2>' . PHP_EOL;
    }
    echo '<h1>Done with page ' . $currentPage . '</h1>' . PHP_EOL;
    sleep(1);
} // End looping through all pages

// Update `tevoDataLoaderStatus` with current info
$statusData['lastRun'] = (string)$now->get(Zend_Date::ISO_8601);
if(isset($statusRow)) {
    $statusRow->setFromArray($statusData);
} else {
    $statusRow = $statusTable->createRow($statusData);
}
$statusRow->save();


echo '<h1>Finished updating `tevo' . $statusData['table'] . '` table</h1>' . PHP_EOL;

if($showStats) {
    $allTimer->endTimer();
    $curMem = new Zend_Measure_Binary(memory_get_usage(true), Zend_Measure_Binary::BYTE);
    $peakMem = new Zend_Measure_Binary(memory_get_peak_usage(true), Zend_Measure_Binary::BYTE);
    echo '<p class="codetimer">Time to complete everything: ' . $allTimer->getElapsedTime() . '</p>' . PHP_EOL
       . '<h2>Current memory usage at end of script: ' . $curMem->convertTo(Zend_Measure_Binary::MEGABYTE) . '</h2>' . PHP_EOL
       . '<h2>PEAK memory usage: ' . $peakMem->convertTo(Zend_Measure_Binary::MEGABYTE) . '</h2>' . PHP_EOL
    ;
}