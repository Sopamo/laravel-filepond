<?php

namespace Sopamo\LaravelFilepond\Tests\Unit;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Crypt;
use Sopamo\LaravelFilepond\Exceptions\InvalidPathException;
use Sopamo\LaravelFilepond\ServerIdCodec;
use Sopamo\LaravelFilepond\TemporaryUpload;
use Sopamo\LaravelFilepond\Tests\TestCase;

class ServerIdCodecTest extends TestCase
{
    public function test_versioned_upload_handle_round_trips_all_metadata(): void
    {
        $upload = new TemporaryUpload('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'local', 'filepond/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.pdf', 'a+b.pdf');
        $codec = app(ServerIdCodec::class);
        $serverId = $codec->encode($upload);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $serverId);
        $this->assertEquals($upload, $codec->decode($serverId));
    }

    public function test_server_ids_use_base64url_and_legacy_transport_encodings_remain_decodable(): void
    {
        $upload = new TemporaryUpload(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'local',
            'filepond/01ARZ3NDEKTSV4RRFFQ69G5FAV/file.pdf',
            'a+b.pdf',
        );
        $payload = json_encode([
            'version' => 3,
            'id' => $upload->id,
            'disk' => $upload->disk,
            'path' => $upload->path,
            'original_name' => $upload->originalName,
            'legacy' => false,
        ], JSON_THROW_ON_ERROR);
        $encryptedPayloads = [];
        $encrypter = $this->createMock(StringEncrypter::class);
        $encrypter->expects($this->exactly(2))
            ->method('encryptString')
            ->willReturn('ab+c/de=');
        $encrypter->expects($this->exactly(3))
            ->method('decryptString')
            ->willReturnCallback(function (string $encryptedPayload) use (&$encryptedPayloads, $payload): string {
                $encryptedPayloads[] = $encryptedPayload;

                return $payload;
            });
        $codec = new ServerIdCodec($encrypter);

        $this->assertSame('ab-c_de', $codec->encode($upload));
        $this->assertSame('ab+c/de=', $codec->encodeLegacyPath($upload->path));
        $this->assertEquals($upload, $codec->decode('ab-c_de'));
        $this->assertEquals($upload, $codec->decode('ab+c/de='));
        $this->assertEquals($upload, $codec->decode('ab c/de='));
        $this->assertSame(['ab+c/de=', 'ab+c/de=', 'ab+c/de='], $encryptedPayloads);
    }

    public function test_encrypted_scalar_json_is_reported_as_an_invalid_upload_id(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('The upload id is invalid.');

        app(ServerIdCodec::class)->decode(Crypt::encryptString('"scalar"'));
    }

    public function test_sibling_prefix_and_traversal_are_rejected(): void
    {
        foreach (['filepond-evil/file.txt', 'filepond/../secret.txt'] as $path) {
            try {
                app(ServerIdCodec::class)->decode(Crypt::encryptString($path));
                $this->fail("Path {$path} was accepted.");
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_version_two_handle_resolves_on_the_configured_disk(): void
    {
        config()->set('filepond.temporary_files_disk', 'temporary');

        $upload = app(ServerIdCodec::class)->decode(Crypt::encryptString('filepond/legacy/file.txt'));

        $this->assertTrue($upload->legacy);
        $this->assertSame('temporary', $upload->disk);
        $this->assertSame('filepond/legacy/file.txt', $upload->path);
    }
}
