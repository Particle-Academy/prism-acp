<?php

declare(strict_types=1);

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;
use Tests\Support\CredentialPaths;
use Tests\Support\CredentialTree;

it('ORIGINAL symlink probe', function (): void {
    $tree = new CredentialTree;
    $target = PrivateDirectory::create('prism-acp-tree-target-');
    $alias = $tree->home.DIRECTORY_SEPARATOR.'link';
    CredentialPaths::link($target, $alias);
    $before = lstat($target);
    try {
        expect(fn () => PrivateDirectory::assertProtected($tree->home))->toThrow(DirectoryProtectionException::class);
        clearstatcache(true, $target);
        expect(lstat($target))->toBe($before);
    } finally {
        CredentialPaths::unlink($alias);
        $tree->remove();
        expect(PrivateDirectory::remove($target))->toBeTrue();
    }
});

