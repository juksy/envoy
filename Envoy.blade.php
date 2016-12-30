@include('envoy.config.php');

@setup
    $server_labels      = [];
    $server_owners      = [];
    $server_userathosts = [];
    $server_hosts       = [];
    $server_ports       = [];
    $server_paths       = [];
    $server_map         = [];

    foreach($server_connections as $row_label => $row_conn_settings) {
        $row_owner = 'www-data';
        $row_conn = '';
        $row_option = '';
        $row_port = 22;

        if ($subset && !preg_match('/^'.$subset.'$/', $row_label)) {
            continue;
        }

        // Get configurations
        if (is_array($row_conn_settings)) {
            $row_owner = array_get($row_conn_settings, 'owner', 'www-data');
            $row_conn = array_get($row_conn_settings, 'conn', '');
            $row_option = array_get($row_conn_settings, 'option', '');
            $row_port = array_get($row_conn_settings, 'port', 22);
        } else if (is_string($row_conn_settings)) {
            $row_conn = $row_conn_settings;
        } else {
            throw new Exception("Invalid the configure on {$row_label}");
        }

        $server_labels[] = $row_label;
        $server_owners[] = $row_owner;
        $server_userathosts[] = $row_conn;
        $server_ports[] = $row_port;
        $server_map[$row_label] = $row_conn .' '. $row_option;
    }

    if (!count($server_map)) {
        throw new Exception('not found any servers.');
    }

    $env = empty($env) ? $settings['env_default'] : $env;
    $beginOn = microtime(true);
    $envoy_servers = array_merge(['local'=>'localhost'], $server_map);

    // Define paths
    $now            = new DateTime();
    $date           = $now->format('YmdHis');
    $app_base       = rtrim($path, '/');
    $tmp_dir        = $app_base.'/tmp';
    $app_dir        = $app_base.'/current';
    $release_dir    = $app_base.'/releases';
    $source_dir     = $release_dir.'/'.$date;

    $spec_procs = array(
        'pack_remotepack'=>array(
            'init_basedir_remote',
            'packrelease_localsrc',
            'rcpreleasepack_to_remote',
            'extractreleasepack_on_remote',
        ),
        'subproc_releasesetup'=>array(
            'rcp_env_to_remote',
            'link_env_on_remote',
            'depsinstall_remotesrc',
            'runtimeoptimize_remotesrc',
        ),
        'subproc_versionsetup'=>array(
            'prepare_remoterelease',
            'syncreleasetoapp_version',
            'cleanupoldreleases_on_remote',
            'cleanup_tempfiles_local',
        ),
    );

    $deploy_macro_context = '';
    $deploy_macro_context .= implode(PHP_EOL,$spec_procs['pack_remotepack']).PHP_EOL;
    $deploy_macro_context .= implode(PHP_EOL,$spec_procs['subproc_releasesetup']).PHP_EOL;
    $deploy_macro_context .= implode(PHP_EOL,$spec_procs['subproc_versionsetup']);
@endsetup

@servers($envoy_servers)

@macro('deploy')
    {{ $deploy_macro_context }}
@endmacro

@task('init_basedir_remote', ['on' => $server_labels, 'parallel' => true])
    [ -d {{ $tmp_dir }} ] || mkdir -p {{ $tmp_dir }};
    [ -d {{ $release_dir }} ] || mkdir -p {{ $release_dir }};

    shareddirs="{{ implode(' ',$shared_subdirs) }}";
    for subdirname in ${shareddirs};
    do
        [ -d {{ $app_base }}/${subdirname} ] || mkdir -p {{ $app_base }}/${subdirname};
    done
@endtask

@task('rcp_env_to_remote', ['on' => 'local'])
    echo "rcp env file to remote...";
    server_userathosts="{{ implode(' ',$server_userathosts) }}";
    server_ports="{{ implode(' ',$server_ports) }}";

    index_count=0;
    for item in $server_userathosts;do
        eval server_userathosts_${index_count}=$item;
        index_count=$((index_count+1));
    done
    index_count=0;
    for item in $server_ports;do
        eval server_ports_${index_count}=$item;
        index_count=$((index_count+1));
    done
    index_length=$((index_count-1));

    for step_index in $(seq 0 $index_length)
    do
        eval step_userathosts=\$server_userathosts_${step_index};
        eval step_ports=\$server_ports_${step_index};
        echo "execute for server: ${step_userathosts} ${step_ports}";
        [ -f .env ] && scp -p${step_ports} .env ${step_userathosts}:{{ $app_base }}/.env;
        [ -f .env.{{ $env }} ] && scp -p${step_ports} .env.{{ $env }} ${step_userathosts}:{{ $app_base }}/.env.{{ $env }};
    done

    echo "rcp env file to remote Done.";
@endtask

@task('packrelease_localsrc', ['on' => 'local'])
    echo "LocalSource Pack release...";
    [ -f release.tgz ] && rm -rf release.tgz;
    tar --exclude=".git*" -czf release.tgz {{ $source_name }};
    echo "LocalSource Pack release Done.";
@endtask

@task('rcpreleasepack_to_remote', ['on' => 'local'])
    echo "rcp localpack release to remote...";
    if [ -f release.tgz ]; then
        server_userathosts="{{ implode(' ',$server_userathosts) }}";
        server_ports="{{ implode(' ',$server_ports) }}";

        index_count=0;
        for item in $server_userathosts;do
            eval server_userathosts_${index_count}=$item;
            index_count=$((index_count+1));
        done
        index_count=0;
        for item in $server_ports;do
            eval server_ports_${index_count}=$item;
            index_count=$((index_count+1));
        done
        index_length=$((index_count-1));

        for step_index in $(seq 0 $index_length)
        do
            eval step_userathosts=\$server_userathosts_${step_index};
            eval step_ports=\$server_ports_${step_index};

            echo "execute for server: ${step_userathosts} ${step_ports}";
            rsync -avz --progress --port ${step_ports} release.tgz ${step_userathosts}:{{ $app_base }}/;
        done
    else
        echo "localpack release NOT EXISTS.";
        echo "Pass [Ctrl-c] to quit.";
        while true; do sleep 100;done;
        exit 1;
    fi
    echo "rcp localpack release to remote Done.";
@endtask

@task('extractreleasepack_on_remote', ['on' => $server_labels, 'parallel' => true])
    echo "extract pack release on remote...";
    if [ -e {{ $tmp_dir }}/service_owner ]; then
        service_owner=`cat {{ $tmp_dir }}/service_owner`;
    else
        service_owner="{{ $settings['service_owner_default'] }}";
    fi

    if [ -f {{ $app_base }}/release.tgz ]; then
        mkdir -m 755 -p {{ $tmp_dir }};
        [ -d {{ $tmp_dir }}/{{ $source_name }} ] && rm -rf {{ $tmp_dir }}/{{ $source_name }};
        tar zxf {{ $app_base }}/release.tgz -C {{ $tmp_dir }};
        if [ -d {{ $tmp_dir }}/{{ $source_name }} ]; then
            mv {{ $tmp_dir }}/{{ $source_name }} {{ $source_dir }};
            rm -rf {{ $app_base }}/release.tgz;
        else
            echo "extract pack release on remote ERROR.";
            echo "Pass [Ctrl-c] to quit.";
            while true; do sleep 100;done;
            exit 1;
        fi
    else
        echo "pack release NOT EXISTS.";
        echo "Pass [Ctrl-c] to quit.";
        while true; do sleep 100;done;
        exit 1;
    fi

    chgrp -Rf ${service_owner} {{ $source_dir }};
    echo "extract pack release on remote Done.";
@endtask

@task('prepare_remoterelease', ['on' => $server_labels, 'parallel' => true])
    echo "RemoteRelease Prepare...";
    if [ -e {{ $tmp_dir }}/service_owner ]; then
        service_owner=`cat {{ $tmp_dir }}/service_owner`;
    else
        service_owner="{{ $settings['service_owner_default'] }}";
    fi

    shareddirs="{{ implode(' ',$shared_subdirs) }}";
    for subdirname in ${shareddirs};
    do
        if [ -e {{ $source_dir }}/${subdirname} ]; then
            if [ ! -L {{ $source_dir }}/${subdirname} ]; then
                mkdir -p {{ $app_base }}/${subdirname};
                rm -rf {{ $source_dir }}/${subdirname};
                ln -nfs {{ $app_base }}/${subdirname} {{ $source_dir }}/${subdirname};
            fi
        else
            mkdir -p {{ $app_base }}/${subdirname};
            rm -rf {{ $source_dir }}/${subdirname};
            ln -nfs {{ $app_base }}/${subdirname} {{ $source_dir }}/${subdirname};
        fi

        chgrp -f ${service_owner} {{ $app_base }}/${subdirname};
        chmod -f ug+rwx {{ $app_base }}/${subdirname};
    done
    echo "RemoteRelease Prepare Done.";
@endtask

@task('syncreleasetoapp_version', ['on' => $server_labels, 'parallel' => true])
    echo "RemoteVersion Sync Release to App...";
    if [ -e {{ $tmp_dir }}/service_owner ]; then
        service_owner=`cat {{ $tmp_dir }}/service_owner`;
    else
        service_owner="{{ $settings['service_owner_default'] }}";
    fi

    [ -L {{ $app_dir }} ] && unlink {{ $app_dir }};
    ln -nfs {{ $source_dir }} {{ $app_dir }};
    chgrp -h ${service_owner} {{ $app_dir }};

    echo "RemoteVersion Sync Release to App Done.";
@endtask

@task('link_env_on_remote', ['on' => $server_labels, 'parallel' => true])
    echo "link env on remote...";
    if [ -e {{ $tmp_dir }}/service_owner ]; then
        service_owner=`cat {{ $tmp_dir }}/service_owner`;
    else
        service_owner="{{ $settings['service_owner_default'] }}";
    fi

    [ -f {{ $app_base }}/.env ] && ln -nfs {{ $app_base }}/.env {{ $source_dir }}/.env;
    [ -f {{ $app_base }}/.env.{{ $env }} ] && ln -nfs {{ $app_base }}/.env.{{ $env }} {{ $source_dir }}/.env;

    chgrp -h ${service_owner} {{ $source_dir }}/.env;

    echo "link env on remote Done.";
@endtask

@task('depsinstall_remotesrc', ['on' => $server_labels, 'parallel' => true])
    echo "RemoteSource Dependencies install...";
    cd {{ $source_dir }};
    if [ {{ intval($settings['deps_install_component']['composer']) }} -eq 1 ]; then
        echo "Composer install...";
        {{ $settings['deps_install_command']['composer'] }};
        echo "Composer installed.";
    fi
    if [ {{ intval($settings['deps_install_component']['npm']) }} -eq 1 ]; then
        echo "NPM install...";
        {{ $settings['deps_install_command']['npm'] }};
        echo "NPM installed.";
    fi
    if [ {{ intval($settings['deps_install_component']['bower']) }} -eq 1 ]; then
        echo "Bower install...";
        {{ $settings['deps_install_command']['bower'] }};
        echo "Bower installed.";
    fi
    if [ {{ intval($settings['deps_install_component']['gulp']) }} -eq 1 ]; then
        echo "gulp build...";
        {{ $settings['deps_install_command']['gulp'] }};
        echo "gulp built.";
    fi
    echo "RemoteSource Dependencies installed.";
@endtask

@task('runtimeoptimize_remotesrc', ['on' => $server_labels, 'parallel' => true])
    echo "RemoteSource Runtime optimize...";
    cd {{ $source_dir }};
    if [ {{ intval($settings['runtime_optimize_component']['composer']) }} -eq 1 ]; then
        echo "Composer optimize...";
        {{ $settings['runtime_optimize_command']['composer'] }};
        echo "Composer optimized.";
    fi
    if [ {{ intval($settings['runtime_optimize_component']['artisan']['optimize']) }} -eq 1 ]; then
        echo "artisan optimize...";
        {{ $settings['runtime_optimize_command']['artisan']['optimize'] }};
        echo "artisan optimized.";
    fi
    if [ {{ intval($settings['runtime_optimize_component']['artisan']['config_cache']) }} -eq 1 ]; then
        echo "artisan config:cache...";
        {{ $settings['runtime_optimize_command']['artisan']['config_cache'] }};
        echo "artisan config:cache done.";
    fi
    if [ {{ intval($settings['runtime_optimize_component']['artisan']['route_cache']) }} -eq 1 ]; then
        echo "artisan route:cache...";
        {{ $settings['runtime_optimize_command']['artisan']['route_cache'] }};
        echo "artisan route:cache done.";
    fi
    echo "RemoteSource Runtime optimized.";
@endtask

@task('cleanupoldreleases_on_remote', ['on' => $server_labels, 'parallel' => true])
    echo 'Cleanup up old releases';
    cd {{ $release_dir }};
    {{--ls -1d release_* | head -n -{{ intval($release_keep_count) }} | xargs -d '\n' rm -Rf;--}}
    (ls -rd {{ $release_dir }}/*|head -n {{ intval($release_keep_count+1) }};ls -d {{ $release_dir }}/*)|sort|uniq -u|xargs rm -rf;
    echo "Cleanup up old releases done.";
@endtask

@task('cleanup_tempfiles_local', ['on' => 'local'])
    echo 'Cleanup Local tempfiles';
    [ -f release.tgz ] && rm -rf release.tgz;
    echo "Cleanup Local tempfiles done.";
@endtask

@after
    if ($task === 'syncreleasetoapp_version') {
        $endOn = microtime(true);
        $totalTime = $endOn - $beginOn;

        if (empty($slack['url'])) {
            return;
        }

        @slack($slack['url'], $slack['channel'], 'Deployed ['. implode(', ', $server_labels) .'] to _'. $env .'_ after '. round($totalTime, 1) .' sec.');
    }
@endafter
