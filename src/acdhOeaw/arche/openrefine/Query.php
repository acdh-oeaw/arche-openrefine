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
use zozlak\queryPart\QueryPart;

/**
 * Description of Query
 *
 * @author zozlak
 */
class Query {

    const STRICT_SHOULD = 'should';
    const STRICT_ALL    = 'all';
    const STRICT_ANY    = 'any';

    static public function fromSuggest(string $prefix, object $config): self {
        return new Query(['query' => "$prefix%"], $config);
    }

    private string $query = '';

    /**
     * 
     * @var array<string>
     */
    private array $type  = [];
    private int $limit = PHP_INT_MAX;

    /**
     * 
     * @var array<string, mixed>
     */
    private array $properties;
    private string $typeStrict = self::STRICT_ALL;
    private object $cfg;

    /**
     * 
     * @param array<string, mixed> $query
     * @param object $config
     */
    public function __construct(array $query, object $config) {
        $this->properties = [];
        foreach ($query as $key => $value) {
            if (isset($this->$key)) {
                if (is_array($this->$key) && !is_array($value)) {
                    $this->$key = [$value];
                } else {
                    $this->$key = $value;
                }
            }
        }
        $this->cfg = $config;
        if (count($this->type) === 0) {
            $this->type = array_map(function ($x) {
                return $x->id;
            }, $this->cfg->types);
        }
    }

    /**
     * 
     * @param PDO $pdo
     * @return array<int, mixed>
     */
    public function getMatches(PDO $pdo): array {
        $typeClause = $this->getTypeWhereClause();
        $outQuery   = $this->getOutputQuery();
        $query      = "
            WITH r AS (
                SELECT
                    coalesce(m1.id, f.iid) AS id, 
                    json_agg(json_build_object('id', coalesce(m1.property, ?::text), 'value', CASE f.raw = ? WHEN true THEN 1.0 ELSE ? END)) AS features
                FROM 
                    full_text_search f 
                    LEFT JOIN metadata m1 USING (mid)
                    JOIN metadata m2 ON coalesce(m1.id, f.iid) = m2.id AND m2.property = ?
                WHERE
                    websearch_to_tsquery('simple', ?) @@ segments
                    AND substring(m2.value, 1, 1000) IN ($typeClause)
                GROUP BY 1
            )
            SELECT id::text, features, type, name, description
            FROM
                r
                $outQuery->query
        ";
        $param      = array_merge(
            [
                $this->cfg->schema->idProp, $this->query, $this->cfg->partialMatchCoefficient,
                $this->cfg->schema->typeProp, $this->query
            ],
            $this->type,
            $outQuery->param,
        );
        $query      = new queryPart($query, $param);
        $stmt       = $pdo->prepare($query->query);
        $stmt->execute($query->param);
        $results    = [];
        $scores     = [];
        while ($i          = $stmt->fetch(PDO::FETCH_OBJ)) {
            $i->name        = $this->getBestLanguage(json_decode($i->name, true));
            $i->description = $this->getBestLanguage(json_decode($i->description, true) ?? [
                ]);
            $i->type        = json_decode($i->type);
            $i->match       = true;
            $i->features    = json_decode($i->features);
            $i->score       = $this->computeScore($i->features);
            $results[]      = $i;
            $scores[]       = $i->score;
        }
        array_multisort($scores, SORT_DESC, SORT_NUMERIC, $results);
        return array_slice($results, 0, $this->limit);
    }

    /**
     * 
     * @param PDO $pdo
     * @param int $offset
     * @return array<int, mixed>
     */
    public function getSuggestEntities(PDO $pdo, int $offset): array {
        $typeClause = $this->getTypeWhereClause();
        $outQuery   = $this->getOutputQuery();
        $query      = "
            WITH r AS (
                SELECT i.id
                FROM identifiers i JOIN metadata m2 USING (id)
                WHERE
                    ids LIKE ?
                    AND m2.property = ?
                    AND substring(m2.value, 1, 1000) IN ($typeClause)
              UNION
                SELECT m1.id
                FROM metadata m1 JOIN metadata m2 USING (id)
                WHERE
                    m1.property = ?
                    AND m1.value LIKE ?
                    AND m2.property = ?
                    AND substring(m2.value, 1, 1000) IN ($typeClause)
            )
            SELECT id::text, name, type AS notable, description
            FROM
                r
                $outQuery->query
            ORDER BY 2
            OFFSET ?
        ";
        $param      = array_merge(
            [$this->query, $this->cfg->schema->typeProp],
            $this->type,
            [$this->cfg->schema->nameProp, $this->query, $this->cfg->schema->typeProp],
            $this->type,
            $outQuery->param,
            [$offset]
        );
        $query      = new queryPart($query, $param);
        $stmt       = $pdo->prepare($query->query);
        $stmt->execute($query->param);
        $results    = [];
        while ($i          = $stmt->fetch(PDO::FETCH_OBJ)) {
            $i->name        = $this->getBestLanguage(json_decode($i->name, true));
            $i->description = $this->getBestLanguage(json_decode($i->description, true) ?? [
                ]);
            $i->notable     = json_decode($i->notable);
            $results[]      = $i;
        }
        return $results;
    }

    /**
     * 
     * @return QueryPart
     */
    private function getOutputQuery(): QueryPart {
        $query = "
            JOIN (
                SELECT id, jsonb_agg(value) AS type
                FROM metadata m
                WHERE
                    m.property = ?
                    AND EXISTS (SELECT 1 FROM r WHERE id = m.id)
                GROUP BY 1
            ) t2 USING (id)
            JOIN (
                SELECT id, jsonb_object_agg(lang, value) AS name
                FROM metadata m
                WHERE
                    m.property = ?
                    AND EXISTS (SELECT 1 FROM r WHERE id = m.id)
                GROUP BY 1
            ) t3 USING (id)
            LEFT JOIN (
                SELECT id, jsonb_object_agg(lang, value) AS description
                FROM metadata m
                WHERE
                    m.property = ?
                    AND EXISTS (SELECT 1 FROM r WHERE id = m.id)
                GROUP BY 1
            ) t4 USING (id)
        ";
        $param = [$this->cfg->schema->typeProp, $this->cfg->schema->nameProp, $this->cfg->schema->descriptionProp];
        $query = new QueryPart($query, $param);
        return $query;
    }

    /**
     * 
     * @param array<string, string> $data
     * @return string|null
     */
    private function getBestLanguage(array $data): ?string {
        foreach ($this->cfg->preferredLangs as $l) {
            if (isset($data[$l])) {
                return $data[$l];
            }
        }
        return array_pop($data) ?? '';
    }

    /**
     * 
     * @return string
     */
    private function getTypeWhereClause(): string {
        return substr(str_repeat(', ?', count($this->type)), 2);
    }

    /**
     * 
     * @param array<string, mixed> $properties
     * @return float
     */
    private function computeScore(array $properties): float {
        $pw    = $this->cfg->propertyWeights;
        $score = 0;
        foreach ($properties as $p) {
            $weight   = isset($pw->{$p->id}) ? $pw->{$p->id} : 1;
            $p->value = $p->value * $weight;
            $score    += $p->value;
        }
        return $score;
    }
}

