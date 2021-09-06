import * as FilePond from 'filepond';
import 'filepond/dist/filepond.min.css';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css';

window.ponds = {};
window.pondMappings = {};

if (FilePond.supported()) {

    FilePond.registerPlugin(
        FilePondPluginImagePreview
    );

    /*var xcsrf_token = (function getCookie(cname) { // https://www.w3schools.com/js/js_cookies.asp
      let name = cname + "=";
      let ca = document.cookie.split(';');
      for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) == ' ') {
          c = c.substring(1);
        }
        if (c.indexOf(name) == 0) {
          return c.substring(name.length, c.length);
        }
      }
      return "";
    })('XSRF-TOKEN');*/

    var csrf_token = null;
    var csrf_meta_tag = document.querySelector('meta[name="csrf-token"]');
    if (csrf_meta_tag) {
        csrf_token = csrf_meta_tag.getAttribute('content');
    }

    FilePond.setOptions({
        credits: false,
        server: {
            url: '/filepond/api',

            process: (fieldName, file, metadata, load, error, progress, abort, transfer, options) => {

                // fieldName is the name of the input field
                // file is the actual file object to send
                const formData = new FormData();

                if (csrf_token) {
                    formData.append("_token", csrf_token);
                }

                formData.append("fieldName", fieldName);
                formData.append(fieldName, file, file.name);

                const request = new XMLHttpRequest();
                request.open('POST', '/filepond/api/process');

                // Should call the progress method to update the progress to 100% before calling load
                // Setting computable to false switches the loading indicator to infinite mode
                request.upload.onprogress = (e) => {
                    progress(e.lengthComputable, e.loaded, e.total);
                };

                // Should call the load method when done and pass the returned server file id
                // this server file id is then used later on when reverting or restoring a file
                // so your server knows which file to return without exposing that info to the client
                request.onload = function () {
                    if (request.status >= 200 && request.status < 300) {
                        // the load method accepts either a string (id) or an object
                        try {
                            var response=JSON.parse(request.responseText);
                            window.pondMappings[response.serverId] = response.fieldName;
                            load(response.serverId);
                        } catch (err) {
                            error('upload response error');
                        }
                    } else {
                        // Can call the error method if something is wrong, should exit after
                        error('upload error');
                    }
                };

                request.send(formData);

                // Should expose an abort method so the request can be cancelled
                return {
                    abort: () => {
                        // This function is entered if the user has tapped the cancel button
                        request.abort();

                        // Let FilePond know the request has been cancelled
                        abort();
                    },
                };
            },

            revert: '/process',
            headers: {
                'X-CSRF-TOKEN': csrf_token
            }
        }
    });

    document.querySelectorAll('.filepond').forEach(element => {
        createPond(element);
    });

    document.addEventListener('DOMContentLoaded', event => {

        // fileupload remove
        document.querySelectorAll('.file-uploads .file-upload a.remove').forEach(a => {
            a.addEventListener('click', function(evt) {

                evt.preventDefault();

                var target = document.querySelector("#"+a.getAttribute("data-for"));

                var data = target.value;
                if (typeof data == "string") data = JSON.parse(data);
                if (typeof data != "object") data = {};
                if (typeof data.c != "object") data.c = [];
                if (typeof data.r != "object") data.r = [];
                if (typeof data.d != "object") data.d = [];
                data.d.push(a.getAttribute("data-value"));
                target.value = JSON.stringify(data);

                a.parentNode.classList.add("d-none");
     
                return false;
            });
        });

    });

    function createPond(element)
    {

        var data = element.getAttribute("data-filepond");

        if (data) {
            try {
                data = JSON.parse(data);
            } catch (err) {
                data = null;
            }
        }

        try {

            var pond = data ? FilePond.create(element, data) : FilePond.create(element);

            pond.on('processfile', (error, file) => {
                if (error) {
                    alert('upload error');
                    return;
                }

                var regex = /^(.+)\-uploader$/;
                var matches = window.pondMappings[file.serverId].match(regex);
                if (matches) {
                    var input = document.querySelector("input#"+matches[1]);

                    var data = input.value;
                    if (typeof data == "string") data = JSON.parse(data);
                    if (typeof data != "object") data = {};
                    if (typeof data.c != "object") data.c = [];
                    if (typeof data.r != "object") data.r = [];
                    if (typeof data.d != "object") data.d = [];
                    data.c.push(file.serverId);

                    input.value = JSON.stringify(data);
                }

            });

        } catch (err) {}

    }

}