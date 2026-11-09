<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\GuestListController;
use App\Controllers\GuestPhotoController;
use App\Controllers\InvitationController;
use App\Controllers\PackageController;
use App\Controllers\RsvpController;
use App\Controllers\SocialLinksController;
use App\Controllers\SubmissionController;
use App\Controllers\TemplateController;
use App\Controllers\UploadController;
use App\Controllers\WishController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Models\AdminModel;
use App\Models\GuestListModel;
use App\Models\GuestPhotoModel;
use App\Models\InvitationModel;
use App\Models\PackageModel;
use App\Models\RsvpModel;
use App\Models\SocialLinksModel;
use App\Models\SubmissionModel;
use App\Models\TemplateModel;
use App\Models\WishModel;
use App\Services\RateLimiter;

return static function (Database $db, Request $request, array $config): array {
    $router = new Router();
    $auth = new AuthController(new AdminModel($db), $config);
    $templateModel = new TemplateModel($db);
    $templates = new TemplateController($templateModel);
    $photos = new GuestPhotoController(new GuestPhotoModel($db), $config['photos']);
    $invitations = new InvitationController(
        new InvitationModel($db),
        $templateModel,
        $photos,
    );
    $uploads = new UploadController($config['media']);
    $guestLists = new GuestListController(new GuestListModel($db));
    $submissions = new SubmissionController(
        new SubmissionModel($db),
        $templateModel,
        $config['submissions'],
        $config['media'],
    );
    $wishes = new WishController(new WishModel($db));
    $rsvps = new RsvpController(new RsvpModel($db));
    $packages = new PackageController(new PackageModel($db));
    $socialLinks = new SocialLinksController(new SocialLinksModel($db));
    $rateLimiter = new RateLimiter($config['storage'] . '/rate-limits');

    $router->add('GET', '/api/health', static function () use ($db) {
        $db->one('SELECT 1');
        return Response::data(['status' => 'ok']);
    });

    $router->add('GET', '/api/auth/session', fn() => $auth->info());
    $router->add(
        'POST',
        '/api/auth/setup',
        fn() => $auth->setup($request->json()),
        rate: 'public',
    );
    $router->add(
        'POST',
        '/api/auth/login',
        fn() => $auth->login($request->json()),
        rate: 'public',
    );
    $router->add('POST', '/api/auth/logout', fn() => $auth->logout($request));

    $router->add('GET', '/api/packages', fn() => $packages->list());
    $router->add('GET', '/api/social-links', fn() => $socialLinks->get());
    $router->add(
        'GET',
        '/api/templates',
        fn() => $templates->list($_GET['category'] ?? null),
    );
    $router->add('GET', '/api/templates/{id}', fn($id) => $templates->find($id));
    $router->add(
        'GET',
        '/api/media/{filename}',
        fn($filename) => $uploads->image($filename),
    );

    $router->add(
        'GET',
        '/api/invitations/{slug}',
        fn($slug) => Response::data($invitations->published($slug)),
    );
    $router->add(
        'GET',
        '/api/invitations/{slug}/wishes',
        fn($slug) => $wishes->list($invitations->published($slug)->id, $_GET),
    );
    $router->add(
        'POST',
        '/api/invitations/{slug}/wishes',
        fn($slug) => $wishes->create(
            $invitations->published($slug)->id,
            $request->json(),
        ),
        rate: 'public',
    );
    $router->add(
        'POST',
        '/api/invitations/{slug}/rsvps',
        fn($slug) => $rsvps->create(
            $invitations->published($slug)->id,
            $request->json(),
        ),
        rate: 'public',
    );
    $router->add(
        'POST',
        '/api/invitations/{slug}/photos',
        fn($slug) => $photos->create(
            $request,
            $invitations->published($slug),
            $_GET['to'] ?? null,
        ),
        rate: 'public',
    );

    $router->add(
        'POST',
        '/api/customer-submissions',
        fn() => $submissions->create($request->json(4194304)),
        rate: 'customer-submissions',
    );
    $router->add(
        'GET',
        '/api/customer-submissions/{id}',
        fn($id) => $submissions->status($request, $id),
    );
    $router->add(
        'POST',
        '/api/customer-submissions/{id}/images/{hash}',
        fn($id, $hash) => $submissions->image($request, $id, $hash),
        rate: 'submission-images',
    );
    $router->add(
        'POST',
        '/api/customer-submissions/{id}/complete',
        fn($id) => $submissions->complete($request, $id),
    );

    $router->add(
        'PATCH',
        '/api/admin/packages/{id}',
        fn($id) => $packages->update($id, $request->json()),
        admin: true,
    );
    $router->add(
        'PATCH',
        '/api/admin/social-links',
        fn() => $socialLinks->update($request->json()),
        admin: true,
    );
    $router->add(
        'POST',
        '/api/admin/templates',
        fn() => $templates->create($request->json(524288)),
        admin: true,
    );
    $router->add(
        'PATCH',
        '/api/admin/templates/{id}',
        fn($id) => $templates->update($id, $request->json(524288)),
        admin: true,
    );
    $router->add(
        'POST',
        '/api/admin/uploads',
        fn() => $uploads->create($request),
        admin: true,
        rate: 'public',
    );

    $router->add(
        'GET',
        '/api/admin/invitations',
        fn() => $invitations->list($_GET),
        admin: true,
    );
    $router->add(
        'POST',
        '/api/admin/invitations',
        fn() => $invitations->create($request->json(1048576)),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}',
        fn($id) => Response::data($invitations->find($id)),
        admin: true,
    );
    $router->add(
        'PATCH',
        '/api/admin/invitations/{id}',
        fn($id) => $invitations->update($id, $request->json(1048576)),
        admin: true,
    );
    $router->add(
        'DELETE',
        '/api/admin/invitations/{id}',
        fn($id) => $invitations->remove($id),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}/guest-list',
        fn($id) => $guestLists->find($id),
        admin: true,
    );
    $router->add(
        'PATCH',
        '/api/admin/invitations/{id}/guest-list',
        fn($id) => $guestLists->update($id, $request->json(2097152)),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}/wishes',
        fn($id) => $wishes->list($invitations->find($id)->id, $_GET),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}/rsvps',
        fn($id) => $rsvps->list($invitations->find($id)->id, $_GET),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}/photos',
        fn($id) => $photos->list($invitations->find($id)->id, $_GET),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/invitations/{id}/photos/{photoId}',
        fn($id, $photoId) => $photos->download($invitations->find($id)->id, $photoId),
        admin: true,
    );
    $router->add(
        'DELETE',
        '/api/admin/wishes/{id}',
        fn($id) => $wishes->remove($id),
        admin: true,
    );

    $router->add(
        'GET',
        '/api/admin/submissions',
        fn() => $submissions->list($_GET),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/submissions/{id}',
        fn($id) => $submissions->detail($id),
        admin: true,
    );
    $router->add(
        'GET',
        '/api/admin/submissions/{id}/images/{hash}',
        fn($id, $hash) => $submissions->downloadImage($id, $hash),
        admin: true,
    );
    $router->add(
        'POST',
        '/api/admin/submissions/{id}/accept',
        fn($id) => $submissions->accept($id, $request->json()),
        admin: true,
    );
    $router->add(
        'POST',
        '/api/admin/submissions/{id}/reject',
        fn($id) => $submissions->reject($id, $request->json()),
        admin: true,
    );
    $router->add(
        'DELETE',
        '/api/admin/submissions/{id}',
        fn($id) => $submissions->remove($id),
        admin: true,
    );

    return $router->dispatch($request, $auth->authorize(...), $rateLimiter->check(...));
};
