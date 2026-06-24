<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Console\Command;

class AssignAdminEmpresa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kore:assign-admin-empresa
                            {email? : Correo del usuario admin a actualizar}
                            {slug? : Slug de la empresa (default: DEFAULT_EMPRESA_SLUG o DecorArte)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Asigna un usuario admin a la empresa indicada por slug.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $slug = $this->argument('slug')
            ?? config('app.default_empresa_slug')
            ?? 'DecorArte';

        $user = $email
            ? User::where('email', $email)->first()
            : User::where('role', 'admin')->orderBy('created_at')->first();

        if (! $user) {
            $this->error('No se encontró un usuario admin' . ($email ? " con email {$email}" : '') . '.');
            return self::FAILURE;
        }

        $empresa = Empresa::where('slug', $slug)
            ->orWhereRaw('LOWER(slug) = LOWER(?)', [$slug])
            ->first();

        if (! $empresa) {
            $this->error("No se encontró una empresa con slug '{$slug}'.");
            return self::FAILURE;
        }

        $previous = $user->empresa_id;
        $user->empresa_id = $empresa->id;
        $user->save();

        $this->info("Usuario actualizado:");
        $this->info("  ID:     {$user->id}");
        $this->info("  Nombre: {$user->name}");
        $this->info("  Email:  {$user->email}");
        $this->info("  Empresa anterior: {$previous}");
        $this->info("  Empresa nueva:    {$empresa->id} ({$empresa->name} / slug: {$empresa->slug})");

        return self::SUCCESS;
    }
}
