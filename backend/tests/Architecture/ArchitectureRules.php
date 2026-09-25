<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

/** Targeted regression guard, not a substitute for reviewing business semantics. */
final class ArchitectureRules
{
    /** @return list<string> */
    public static function violations(string $path, string $source, array $adapters): array
    {
        $errors = [];
        if (!preg_match('#^(Controller|Entity|Repository)/|^Service/[^/]+/[^/]+#', $path)
            && !isset($adapters[$path])) {
            $errors[] = 'Business code belongs in Service/<domain>; technical adapters must be documented.';
        }

        // Comments and string payloads must not trigger code rules. Whitespace is
        // retained so qualified names, aliases and multiline calls behave alike.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) ? ' ' : $token[1];
            } else {
                $code .= $token;
            }
        }

        if (str_starts_with($path, 'Controller/')) {
            if (preg_match('/(?:->|\?->|::)\s*(beginTransaction|commit|rollBack|transactional|wrapInTransaction|persist|flush|lock|executeStatement|executeQuery|createQueryBuilder|createQuery|remove)\s*\(/i', $code)) {
                $errors[] = 'Transactions, persistence and SQL orchestration belong in Service/Repository.';
            }
            if (preg_match('/\b(?:Doctrine\\\\DBAL|Stripe\\\\|Symfony\\\\Component\\\\(?:Mailer|Messenger|Workflow)|SM\\\\)/i', $code)) {
                $errors[] = 'Controllers must delegate provider, message and workflow orchestration.';
            }
            if (str_contains($code, 'Doctrine\\ORM\\') && ($path !== 'Controller/ShopBookingApiController.php' || str_contains(str_replace('Doctrine\\ORM\\EntityManagerInterface', '', $code), 'Doctrine\\ORM\\'))) {
                $errors[] = 'No new EntityManager dependency in controllers (documented shop read exception only).';
            }
            if (preg_match('/(?:->|\?->)\s*(set(?!Cookie\b)[A-Z]\w*|add[A-Z]\w*|remove[A-Z]\w*)\s*\(/', $code)) {
                $errors[] = 'Entity mutation belongs in a service; HTTP cookie adaptation is allowed.';
            }
        }
        // Doctrine repositoryClass metadata/imports are mapping, not runtime access.
        $code = preg_replace('/use App\\\\Repository\\\\[A-Za-z]+;/', '', $code);
        if (str_starts_with($path, 'Entity/') && preg_match('/\b(?:App\\\\(?:Service|Repository|Controller)\\\\|Symfony\\\\Component\\\\HttpFoundation\\\\)/', $code)) {
            // Persisted team role enum is a local value, not a service dependency.
            $withoutRole = str_replace('App\\Service\\Security\\TeamRole', '', $code);
            if (preg_match('/\b(?:App\\\\(?:Service|Repository|Controller)\\\\|Symfony\\\\Component\\\\HttpFoundation\\\\)/', $withoutRole)) {
                $errors[] = 'Entities keep local invariants, without service/repository/HTTP dependencies.';
            }
        }

        return $errors;
    }
}
