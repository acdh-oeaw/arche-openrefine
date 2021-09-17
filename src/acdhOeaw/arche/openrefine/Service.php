<?php

/*
 * The MIT License
 *
 * Copyright 2021 Austrian Centre for Digital Humanities and Cultural Heritage.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\openrefine;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Description of Service
 *
 * @author zozlak
 */
class Service {

    const SUGGESTTYPE_ENTITY   = 'entity';
    const SUGGESTTYPE_TYPE     = 'type';
    const SUGGESTTYPE_PROPERTY = 'property';

    private object $cfg;
    private PDO $pdo;

    /**
     * 
     * @param object $config
     */
    public function __construct(object $config) {
        $this->cfg = $config;
        $this->pdo = new PDO('pgsql: ' . $this->cfg->dbConnStr);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->query("SET TRANSACTION READ ONLY");
        $this->pdo->query("SET application_name TO openrefineapi");
    }

    /**
     * 
     * @return void
     * @throws RuntimeException
     */
    public function handleRequest(): void {
        try {
            $resp = null;
            $this->handleCors();
            $basePath = parse_url($this->cfg->baseUrl, PHP_URL_PATH);
            $path = substr($_SERVER['REQUEST_URI'], strlen($basePath));
            $path = preg_replace('|/?([?].*)?$|', '', $path) ?: '';
            switch ($path) {
                case 'reconcile':
                    $resp = count($_GET) > 0 ? $this->handleReconcile() : $this->handleManifest();
                    break;
                case 'preview':
                    $this->handlePreview();
                    break;
                case 'suggest/' . self::SUGGESTTYPE_ENTITY:
                case 'suggest/' . self::SUGGESTTYPE_TYPE:
                case 'suggest/' . self::SUGGESTTYPE_PROPERTY:
                    $resp = $this->handleSuggest(preg_replace('|^.*/|', '', $path) ?: '');
                    break;
                case 'dataExtension':
                    $resp = $this->handleDataExtension();
                    break;
                default:
                    throw new RuntimeException("Page not found. Service's manifest is available on " . $this->cfg->baseUrl . "reconcile", 404);
            }
            if ($resp !== null) {
                header('Content-Type: application/json');
                echo json_encode($resp);
            }
        } catch (Throwable $ex) {
            $code = $ex->getCode();
            $code = $code >= 400 && $code <= 599 ? $code : 500;
            http_response_code($code);
            if ($this->cfg->debug) {
                print_r($ex);
            } else {
                echo $ex->getMessage();
            }
        }
    }

    /**
     * 
     * @return void
     */
    private function handleCors(): void {
        $cors = $this->cfg->cors ?? '*';
        if ($cors === '__secure__') {
            $cors = filter_input(INPUT_SERVER, 'HTTP_ORIGIN');
            header('Vary: origin');
        }
        header("Access-Control-Allow-Origin: $cors");
    }

    /**
     * 
     * @return object
     */
    private function handleManifest(): object {
        $data = [
            'versions'        => ['0.1', '0.2'],
            'name'            => $this->cfg->name,
            'identifierSpace' => $this->cfg->identifierSpace,
            'schemaSpace'     => $this->cfg->schemaSpace,
            'defaultTypes'    => $this->cfg->types ?? [['id' => 'defaultType', 'name' => 'defaultType']],
            /*
              'view'            => [
              'url' => $this->cfg->baseUrl . 'preview?id={{id}}',
              ],
              'feature_view'    => [
              'url' => $this->cfg->baseUrl . 'preview?id={{id}}',
              ],
             */
            'preview'         => [
                'url'    => $this->cfg->baseUrl . 'preview?id={{id}}',
                'width'  => 100,
                'height' => 100,
            ],
            'suggest'         => [
                'entity' => [
                    'service_url'  => $this->cfg->baseUrl . 'suggest',
                    'service_path' => '/' . self::SUGGESTTYPE_ENTITY,
                ],
                'type'   => [
                    'service_url'  => $this->cfg->baseUrl . 'suggest',
                    'service_path' => '/' . self::SUGGESTTYPE_TYPE,
                ],
            /*
              'property' => [
              'service_url'  => $this->cfg->baseUrl . 'suggest',
              'service_path' => '/' . self::SUGGESTTYPE_PROPERTY,
              ],
             */
            ],
            /*
              'extend'          => [
              'propose_properties' => [
              'service_url'  => '',
              'service_path' => '',
              ],
              'property_settings'  => [
              [
              'name'      => '',
              'label'     => '',
              'type'      => '',
              'default'   => '',
              'help_test' => '',
              'choices'   => [''],
              ],
              ]
              ],
             */
        ];
        return (object) $data;
    }

    /**
     * 
     * @return object
     * @throws RuntimeException
     */
    private function handleReconcile(): object {
        $queries = $_GET['queries'] ?? $_POST['queries'] ?? '[]';
        $queries = json_decode($queries, true);
        if ($queries === false || !is_array($queries)) {
            throw new RuntimeException('Bad request', 400);
        }
        $response = [];
        foreach ($queries as $queryId => $query) {
            $query              = new Query($query, $this->cfg);
            $response[$queryId] = ['result' => $query->getMatches($this->pdo)];
        }
        return (object) $response;
    }

    /**
     * 
     * @return void
     */
    private function handlePreview(): void {
        $id = $_GET['id'] ?? '';
        http_response_code(302);
        header('Location: ' . $this->cfg->identifierSpace . "$id/metadata");
    }

    /**
     * 
     * @param string $type
     * @return array<int, mixed>
     * @throws RuntimeException
     */
    private function handleSuggest(string $type): array {
        $prefix = $_GET['prefix'] ?? '';
        $cursor = (int) ($_GET['cursor'] ?? 0); // how many to skip
        switch ($type) {
            case self::SUGGESTTYPE_ENTITY:
                return $this->handleSuggestEntity($prefix, $cursor);
            case self::SUGGESTTYPE_TYPE:
                return $this->handleSuggestType($prefix, $cursor);
            default:
                throw new RuntimeException("Unsupported suggest type $type");
        }
    }

    /**
     * 
     * @param string $prefix
     * @param int $cursor
     * @return array<int, mixed>
     */
    private function handleSuggestEntity(string $prefix, int $cursor): array {
        $query = Query::fromSuggest($prefix, $this->cfg);
        $results = $query->getSuggestEntities($this->pdo, $cursor);
        return ['result' => $results];
    }

    /**
     * 
     * @param string $prefix
     * @param int $cursor
     * @return array<int, mixed>
     */
    private function handleSuggestType(string $prefix, int $cursor): array {
        $prefix  = mb_strtolower($prefix);
        $results = [];
        foreach ($this->cfg->types as $type) {
            if (str_starts_with($type->id, $prefix) || str_starts_with($type->name, $prefix)) {
                $results[] = $type;
            }
        }
        return ['result' => $results];
    }
    
    /**
     * 
     * @return object
     * @throws RuntimeException
     */
    private function handleDataExtension(): object {
        throw new RuntimeException('Not implemented', 404);
    }
}
