<?php
// This dummy class strictly holds global OpenAPI attributes for the Zircote generator.

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "PharmaStock Core API",
    description: "Decoupled PHP Backend supplying strict JSON endpoints for the PharmaStock WMS interface."
)]
#[OA\Server(
    url: "/pharma-stock/backend/api.php",
    description: "Local Development Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
class OpenApiSpec {
}

class ProceduralProxyEndpoints {
    #[OA\Get(path: "/users", summary: "List User Accounts", tags: ["Users & Profiles"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "Array")])]
    public function usersGet() {}
    
    #[OA\Post(path: "/users", summary: "Allocate Account", tags: ["Users & Profiles"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 201, description: "Allocated")])]
    public function usersPost() {}
    
    #[OA\Put(path: "/users/{id}", summary: "Modify Account Parameters", tags: ["Users & Profiles"], security: [["bearerAuth" => []]], parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))], responses: [new OA\Response(response: 200, description: "OK")])]
    public function usersPut() {}
    
    #[OA\Delete(path: "/users/{id}", summary: "Purge System Administrator", tags: ["Users & Profiles"], security: [["bearerAuth" => []]], parameters: [new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))], responses: [new OA\Response(response: 200, description: "OK")])]
    public function usersDelete() {}

    #[OA\Get(path: "/users/me", summary: "Fetch Personal Profile Matrix", tags: ["Users & Profiles"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "Self")])]
    public function usersMeGet() {}

    #[OA\Get(path: "/suppliers", summary: "View Active Suppliers", tags: ["Suppliers"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "List")])]
    public function supplierGet() {}

    #[OA\Post(path: "/suppliers", summary: "Enter Supplier Agency", tags: ["Suppliers"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "Added")])]
    public function supplierPost() {}

    #[OA\Get(path: "/settings", summary: "Fetch Global Parameters", tags: ["Core Engine Settings"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "System Vars")])]
    public function settingsGet() {}

    #[OA\Get(path: "/notifications", summary: "Pull Active User Alerts", tags: ["Core Engine Settings"], security: [["bearerAuth" => []]], responses: [new OA\Response(response: 200, description: "Found Alerts")])]
    public function notifGet() {}
}
