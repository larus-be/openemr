<?php

// +-----------------------------------------------------------------------------+
// Copyright (C) 2012 Z&H Consultancy Services Private Limited <sam@zhservices.com>
//
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
//
// A copy of the GNU General Public License is included along with this program:
// openemr/interface/login/GnuGPL.html
// For more information write to the Free Software
// Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
//
// Author:   Eldho Chacko <eldho@zhservices.com>
//           Jacob T Paul <jacob@zhservices.com>
//
// +------------------------------------------------------------------------------+

use OpenEMR\Common\Crypto\CryptoGen;

class CouchDB
{
    function __construct()
    {
        $this->host = $GLOBALS['couchdb_host'];
        $this->user = ($GLOBALS['couchdb_user'] != '') ? $GLOBALS['couchdb_user'] : null;
        $cryptoGen = new CryptoGen();
        $this->pass = ($cryptoGen->decryptStandard($GLOBALS['couchdb_pass']) != '') ? $cryptoGen->decryptStandard($GLOBALS['couchdb_pass']) : null;
        $this->port = $GLOBALS['couchdb_port'];
        $this->dbase = $GLOBALS['couchdb_dbase'];
    }

    function check_connection()
    {
        $resp = $this->send("GET", "/"); // response: string(46) "{"couchdb": "Welcome", "version": "0.7.0a553"}"
        $response = json_decode($resp);
        if ($response->couchdb && $response->version) {
            return true;
        } else {
            return false;
        }
    }

    function createDB($db)
    {
        $resp = $this->send("PUT", "/" . $db);
        return true;
    }

    function createView($db)
    {

        $resp = $this->send("PUT", "/" . $db . "/_design/FilteringViews", '{"_id":"_design/FilteringViews","views": {"FilterPid": {"map": "function(doc) { if(doc.pid){emit(doc._id, doc);} }"},
                                                                                                                "FilterEncounter": {"map": "function(doc) { if(doc.encounter){emit(doc._id, doc);} }"},
                                                                                                                "FilterPidEncounter": {"map": "function(doc) { if(doc.pid && doc.encounter){emit(doc._id, doc);} }"}}}');
        return json_decode($resp);
    }

    function check_saveDOC($data)
    {
        list($db,$docid,$patient_id,$encounter,$type,$document, $th_document) = $data;
        $couch_json = array();
        $couch_json['_id'] = $docid;
        $couch_json['pid'] = $patient_id;
        $couch_json['encounter'] = $encounter;
        $couch_json['mimetype'] = $type;
        $couch_json['data'] = $document;
        if ($th_document) {
            $couch_json['th_data'] = $th_document;
        }

        $resp = $this->send("PUT", "/" . $db . "/" . $docid, json_encode($couch_json));
        return json_decode($resp);
    }

    function update_doc($data)
    {
        list($db,$docid,$revid,$patient_id,$encounter,$type,$json, $th_json) = $data;
        $couch_json = array();
        $couch_json['_id'] = $docid;
        $couch_json['_rev'] = $revid;
        $couch_json['pid'] = $patient_id;
        $couch_json['encounter'] = $encounter;
        $couch_json['mimetype'] = $type;
        $couch_json['data'] = $json;
        if ($th_json) {
            $couch_json['th_data'] = $th_json;
        }

        $resp = $this->send("PUT", "/" . $db . "/" . $docid, json_encode($couch_json));
        return json_decode($resp);
    }

    function DeleteDoc($db, $docid, $revid)
    {
        $resp = $this->send("DELETE", "/" . $db . "/" . $docid . "?rev=" . $revid);
        return true;
    }

    function retrieve_doc($data)
    {
        list($db,$docid) = $data;
        $resp = $this->send("GET", "/" . $db . "/" . $docid);
        return json_decode($resp); // string(47) "{"_id":"123","_rev":"2039697587","data":"Foo"}"
    }

    function stringToId($string, $replace = '_')
    {
        // First translit string to ASCII, as this characters are most probably
        // supported everywhere
        // Note that musl library (used in alpine os) does not support below iconv
        //  call, so if return is empty, then will try another iconv call, and
        //  if that is empty, will then not do iconv.
        $string_iconv = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        if (empty($string_iconv)) {
            $string_iconv = iconv('UTF-8', 'ASCII', $string);
        }
        if (empty($string_iconv)) {
            $string_iconv = $string;
        }
        $string = $string_iconv;

        // And then still replace any obscure characters by _ to ensure nothing
        // "bad" happens with this string.
        $string = preg_replace('([^A-Za-z0-9.-]+)', $replace, $string);

        // Additionally we convert the string to lowercase, so that we get case
        // insensitive fetching
        return strtolower($string);
    }

    function send($method, $url, $post_data = null)
    {
        if ($GLOBALS['couchdb_connection_ssl']) {
            // encrypt couchdb over the wire
            if (
                file_exists($GLOBALS['OE_SITE_DIR'] . "/documents/certificates/couchdb-ca") &&
                file_exists($GLOBALS['OE_SITE_DIR'] . "/documents/certificates/couchdb-cert") &&
                file_exists($GLOBALS['OE_SITE_DIR'] . "/documents/certificates/couchdb-key")
            ) {
                // support cacert_file and client certificates
                $stream_context = stream_context_create(
                    [
                        'ssl' =>
                            [
                                'cafile' => "${GLOBALS['OE_SITE_DIR']}/documents/certificates/couchdb-ca",
                                'local_cert' => "${GLOBALS['OE_SITE_DIR']}/documents/certificates/couchdb-cert",
                                'local_pk' => "${GLOBALS['OE_SITE_DIR']}/documents/certificates/couchdb-key"
                            ]
                    ]
                );
                $s = stream_socket_client('ssl://' . $this->host . ":" . $this->port, $errno, $errstr, ini_get("default_socket_timeout"), STREAM_CLIENT_CONNECT, $stream_context);
            } elseif (file_exists($GLOBALS['OE_SITE_DIR'] . "/documents/certificates/couchdb-ca")) {
                // support cacert_file
                $stream_context = stream_context_create(
                    [
                        'ssl' =>
                            [
                                'cafile' => "${GLOBALS['OE_SITE_DIR']}/documents/certificates/couchdb-ca"
                            ]
                    ]
                );
                $s = stream_socket_client('ssl://' . $this->host . ":" . $this->port, $errno, $errstr, ini_get("default_socket_timeout"), STREAM_CLIENT_CONNECT, $stream_context);
            } else {
                if ($GLOBALS['couchdb_ssl_allow_selfsigned']) {
                    // support self-signed
                    $stream_context = stream_context_create(
                        [
                            'ssl' =>
                                [
                                    'verify_peer' => false,
                                    'allow_self_signed' => true
                                ]
                        ]
                    );
                    $s = stream_socket_client('ssl://' . $this->host . ":" . $this->port, $errno, $errstr, ini_get("default_socket_timeout"), STREAM_CLIENT_CONNECT, $stream_context);
                } else {
                    // self-signed, not supported so do not proceed and return false
                    return false;
                }
            }
        } else {
            // do not encrypt couchdb over the wire
            $s = stream_socket_client('tcp://' . $this->host . ":" . $this->port, $errno, $errstr);
        }

        if (!$s) {
            return false;
        }

        $request = "$method $url HTTP/1.0\r\nHost: $this->host\r\n";

        if ($this->user) {
            $request .= 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->pass) . "\r\n";
        }

        if ($post_data) {
            $request .= "Content-Length: " . strlen($post_data) . "\r\n\r\n";
            $request .= "$post_data\r\n";
        } else {
            $request .= "\r\n";
        }

        fwrite($s, $request);
        $response = "";

        while (!feof($s)) {
            $response .= fgets($s);
        }

        list($this->headers, $this->body) = explode("\r\n\r\n", $response);
        return $this->body;
    }
}
