import './bootstrap';
import collapse from '@alpinejs/collapse';
import mask from '@alpinejs/mask';
import resize from '@alpinejs/resize';
import intersect from '@alpinejs/intersect';
import sort from '@alpinejs/sort';

Alpine.plugin(collapse);
Alpine.plugin(mask);
Alpine.plugin(resize);
Alpine.plugin(intersect);
Alpine.plugin(sort);

(function () {
  'use strict';

  // MetisMenu js
  function initMetisMenu() {
    // MetisMenu js
      if (document.getElementById("side-menu")) {
        new MetisMenu('#side-menu');
      }
      
  }

  // initLeftMenuCollapse
  function initLeftMenuCollapse() {
    var currentSIdebarSize = document.body.getAttribute('data-sidebar-size');
    window.onload = function () {
      if (window.innerWidth >= 1024 && window.innerWidth <= 1366) {
        document.body.setAttribute('data-sidebar-size', 'sm');
      }
    }
    var verticalButton = document.getElementsByClassName("vertical-menu-btn");
    for (var i = 0; i < verticalButton.length; i++) {
      (function (index) {
        verticalButton[index] && verticalButton[index].addEventListener('click', function (event) {
          event.preventDefault();
          document.body.classList.toggle('sidebar-enable');
          if (window.innerWidth >= 992) {
            if (currentSIdebarSize == null) {
              (document.body.getAttribute('data-sidebar-size') == null || document.body.getAttribute('data-sidebar-size') == "lg") ? document.body.setAttribute('data-sidebar-size', 'sm') : document.body.setAttribute('data-sidebar-size', 'lg')
            } else if (currentSIdebarSize == "md") {
              (document.body.getAttribute('data-sidebar-size') == "md") ? document.body.setAttribute('data-sidebar-size', 'sm') : document.body.setAttribute('data-sidebar-size', 'md')
            } else {
              (document.body.getAttribute('data-sidebar-size') == "sm") ? document.body.setAttribute('data-sidebar-size', 'lg') : document.body.setAttribute('data-sidebar-size', 'sm')
            }
          } else {
            initMenuItemScroll();
          }
        });
      })(i);
    }
  }

  // menu active
  function initActiveMenu() {
    var menuItems = document.querySelectorAll("#sidebar-menu a");
    menuItems && menuItems.forEach(function (item) {
      var pageUrl = window.location.href.split(/[?#]/)[0];

      if (item.href == pageUrl) {
        item.classList.add("active");
        var parent = item.parentElement;
        if (parent && parent.id !== "side-menu") {
          parent.classList.add("mm-active");
          var parent2 = parent.parentElement; // ul .
          if (parent2 && parent2.id !== "side-menu") {
            parent2.classList.add("mm-show"); // ul tag
            var parent3 = parent2.parentElement; // li tag
            if (parent3 && parent3.id !== "side-menu") {
              parent3.classList.add("mm-active"); // li
              var parent4 = parent3.parentElement; // ul
              if (parent4 && parent4.id !== "side-menu") {
                parent4.classList.add("mm-show"); // ul
                var parent5 = parent4.parentElement;
                if (parent5 && parent5.id !== "side-menu") {
                  parent5.classList.add("mm-active"); // li
                }
              }
            }
          }
        }
      }
    });
  }


  // sidebarMenu

  function initMenuItemScroll() {
    setTimeout(function () {
      var sidebarMenu = document.getElementById("side-menu");
      if (sidebarMenu) {
        var activeMenu = sidebarMenu.querySelector(".mm-active .active");
        var offset = activeMenu ? activeMenu.offsetTop : 0;
        if (offset > 300) {
          var verticalMenu = document.getElementsByClassName("vertical-menu") ? document.getElementsByClassName("vertical-menu")[0] : "";
          if (verticalMenu && verticalMenu.querySelector(".simplebar-content-wrapper")) {
            setTimeout(function () {
              offset == 330 ?
                (verticalMenu.querySelector(".simplebar-content-wrapper").scrollTop = offset + 85) :
                (verticalMenu.querySelector(".simplebar-content-wrapper").scrollTop = offset);
            }, 0);
          }
        }
      }
    }, 250);
  }

  function init() {
    initMetisMenu();
    initLeftMenuCollapse();
    initActiveMenu();
    initMenuItemScroll();
  }

  init();

})();

if (document.getElementById('studio-editor')) {
  import('./pagebuilder.js');
}





feather.replace()

